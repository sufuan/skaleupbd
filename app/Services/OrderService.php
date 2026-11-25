<?php

namespace App\Services;

use App\Models\ComboComponent;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The order book's core operations: intake, approve, lifecycle moves, stock. */
class OrderService
{
    /**
     * Statuses that mean the order has entered the fulfilment pipeline -
     * every path landing an order in one of these stamps approved_at
     * exactly once (Round 6, P9). The dashboard's default "Approved date"
     * cohort reads approved_at, so an intake that skips the stamp (the
     * WooCommerce importer, the external API, a direct service create)
     * would silently vanish from the headline numbers.
     */
    private const APPROVED_STATUSES = ['Processing', 'Shipped', 'Delivered', 'Returned'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SuccessRateService $fraud,
        private readonly TokenService $tokens,
        private readonly StockService $stock,
    ) {}

    public function nextInvoice(int $businessId, ?string $prefix = null): string
    {
        $slug = $prefix !== null && trim($prefix) !== '' ? trim($prefix) : (string) $this->settings->bizGet($businessId, "business.{$businessId}.invoice_slug", 'business.invoice_slug', 'OB');
        $n = (int) $this->settings->get('business.invoice_seq', 281);
        do {
            $n++;
            $invoice = $slug.$n;
        } while (Order::where('business_id', $businessId)->where('invoice_no', $invoice)->exists());
        $this->settings->set('business.invoice_seq', $n);

        return $invoice;
    }

    /** Find-or-create the customer for a phone inside the business (normalized). */
    public function customerFor(int $businessId, string $phone, array $attrs = []): Customer
    {
        $phone = PhoneNormalizer::normalize($phone);
        $customer = Customer::firstOrCreate(
            ['business_id' => $businessId, 'phone' => $phone],
            ['name' => $attrs['name'] ?? null, 'email' => $attrs['email'] ?? null,
                'address' => $attrs['address'] ?? null, 'district' => $attrs['district'] ?? null, 'area' => $attrs['area'] ?? null]
        );
        $fill = array_filter(['name' => $attrs['name'] ?? null, 'email' => $attrs['email'] ?? null,
            'address' => $attrs['address'] ?? null, 'district' => $attrs['district'] ?? null, 'area' => $attrs['area'] ?? null]);
        if ($fill) {
            $customer->fill($fill)->save();
        }

        return $customer;
    }

    /**
     * Create an order in the one list. $data keys: customer_name, customer_phone,
     * customer_email, customer_address, district, area, source, site, status,
     * delivery_method, delivery_note, shipping_note, tags[], discount,
     * delivery_charge, advance, is_preorder, preorder_reason, notify_date,
     * external_ref, idempotency_key, ip, device, items[] {product_id?, name, sku?, qty, price}.
     */
    public function create(int $businessId, array $data, ?User $actor = null, string $createdVia = 'Manual entry'): Order
    {
        return DB::transaction(function () use ($businessId, $data, $actor, $createdVia) {
            // M19 points gate: an order may only ENTER when the business
            // can afford it. The deduction itself happens below, inside
            // this same transaction, exactly once per order.
            if (! $this->tokens->canAfford($businessId)) {
                throw new \App\Exceptions\InsufficientTokensException(TokenService::BLOCK_MESSAGE);
            }
            // The New Order form has no district field (prototype layout): when
            // the caller did not supply one, read it out of the address text.
            if (empty($data['district'])) {
                $data['district'] = self::deriveDistrict($data['customer_address'] ?? null);
            }
            $phone = PhoneNormalizer::normalize($data['customer_phone'] ?? '');
            $customer = $this->customerFor($businessId, $phone, [
                'name' => $data['customer_name'] ?? null, 'email' => $data['customer_email'] ?? null,
                'address' => $data['customer_address'] ?? null, 'district' => $data['district'] ?? null, 'area' => $data['area'] ?? null,
            ]);

            $items = collect($data['items'] ?? [])->map(function ($i) use ($businessId) {
                $product = ! empty($i['product_id']) ? Product::where('business_id', $businessId)->find($i['product_id']) : null;
                // The line must remember WHICH variant it is for (K-H2):
                // intake paths carry the id (storefront, New Order form)
                // or only the name (chat intake) - resolve either to the
                // row, so every stock path below can move the variant's
                // own stock pool.
                $variant = $product ? $this->variantForItem($product, $i) : null;
                return [
                    'product' => $product,
                    'product_id' => $product?->id,
                    'name' => $i['name'] ?? $product?->name ?? 'Item',
                    'sku' => $i['sku'] ?? $product?->sku,
                    'variant_id' => $variant?->id,
                    'variant_name' => $i['variant_name'] ?? $variant?->name,
                    'qty' => (int) ($i['qty'] ?? 1),
                    'price' => (float) ($i['price'] ?? $variant?->effectivePrice() ?? $product?->price ?? 0),
                    'cost' => $product ? $this->lineUnitCost($product, $variant) : (float) ($i['cost'] ?? 0),
                ];
            });
            // R5: an order created straight into Processing reserves in
            // full below, so it must pass the same shortage discipline
            // approve() applies - otherwise the reservation (and the
            // later ship) drives stock negative for intakes that never
            // pass an approve step (Woo/API imports, Approved-mode
            // entry, service creates). Pending intake stays unchecked:
            // nothing is reserved until approval, and intake must never
            // fail before that point. Throwing here rolls the whole
            // create back - no order, no point spent, no stock moved.
            if (($data['status'] ?? 'Pending') === 'Processing') {
                $short = $this->shortagesForItems($businessId, $items->map(fn ($i) => [
                    'product_id' => $i['product_id'], 'qty' => $i['qty'],
                    'variant_id' => $i['variant_id'] ?? null, 'variant_name' => $i['variant_name'] ?? null,
                ])->all());
                if ($short) {
                    throw ValidationException::withMessages(['stock' => 'Not enough stock - '.implode('; ', $short)]);
                }
            }
            $subtotal = $items->sum(fn ($i) => $i['price'] * $i['qty']);
            $discount = (float) ($data['discount'] ?? 0);
            $delivery = (float) ($data['delivery_charge'] ?? 0);
            $advance = (float) ($data['advance'] ?? 0);

            // Deferred network check: intake stores the local-history verdict
            // plus any already-cached courier-network result. When a network
            // figure is still outstanding, RefreshFraudCheckJob (dispatched
            // below, once the order exists) completes the snapshot off the
            // request path — a courier API must never hold up order creation.
            $check = $this->fraud->forPhone($businessId, $phone, deferNetwork: true);

            $order = Order::create([
                'business_id' => $businessId,
                'invoice_no' => $data['invoice_no'] ?? $this->nextInvoice($businessId, $data['invoice_prefix'] ?? null),
                // Unguessable key for the public order-success page
                // (Hunt U3): invoice numbers are sequential, so the
                // success URL carries this token and the page 404s
                // without it. Minted here so EVERY creation path
                // (storefront, chat, API, Woo, manual) issues one.
                'success_token' => \Illuminate\Support\Str::random(48),
                'customer_id' => $customer->id,
                'customer_name' => $data['customer_name'] ?? $customer->name ?? '',
                'customer_phone' => $phone,
                'customer_email' => $data['customer_email'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'district' => $data['district'] ?? null,
                'area' => $data['area'] ?? null,
                'source' => $data['source'] ?? 'DIRECT',
                'site' => $data['site'] ?? null,
                'status' => $data['status'] ?? 'Pending',
                // Created already inside the pipeline (Woo importer,
                // external API, Approved-mode form): the approval moment
                // is the creation moment (Round 6, P9).
                'approved_at' => in_array($data['status'] ?? 'Pending', self::APPROVED_STATUSES, true) ? now() : null,
                'delivery_method' => $data['delivery_method'] ?? null,
                'delivery_note' => $data['delivery_note'] ?? null,
                'shipping_note' => $data['shipping_note'] ?? null,
                'tags' => $data['tags'] ?? [],
                'payment_method' => ! empty($data['payment_method']) ? $data['payment_method'] : 'COD',
                'reference_link' => $data['reference_link'] ?? null,
                'cross_sale' => (bool) ($data['cross_sale'] ?? false),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'delivery_charge' => $delivery,
                'advance' => $advance,
                'grand_total' => $subtotal - $discount + $delivery,
                'is_preorder' => (bool) ($data['is_preorder'] ?? false),
                // The reason belongs to preorders only - the New Order
                // form always submits its default ('Out of Stock'), so
                // storing it unconditionally put a preorder reason on
                // ordinary orders.
                'preorder_reason' => ! empty($data['is_preorder']) ? ($data['preorder_reason'] ?? null) : null,
                'notify_date' => $data['notify_date'] ?? null,
                'external_ref' => $data['external_ref'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                // Z1-3: the New Order form's per-mount submission token,
                // unique per business (migration 000057) - a duplicated
                // submission dies on the index instead of booking twice.
                'submit_token' => $data['submit_token'] ?? null,
                // M18 storefront attribution (saved on every storefront order).
                'storefront_id' => $data['storefront_id'] ?? null,
                'storefront_page_id' => $data['storefront_page_id'] ?? null,
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'fbclid' => $data['fbclid'] ?? null,
                'ip' => $data['ip'] ?? null,
                'device' => $data['device'] ?? null,
                'fraud_verdict' => $check['verdict'],
                'fraud_rate' => $check['rate'],
                'fraud_note' => $check['note'],
                'taken_by' => $actor?->name,
                'attachments' => $data['attachments'] ?? [],
            ]);

            foreach ($items as $i) {
                $order->items()->create([
                    'product_id' => $i['product_id'],
                    'name' => $i['name'],
                    'sku' => $i['sku'],
                    'variant_id' => $i['variant_id'] ?? null,
                    'variant_name' => $i['variant_name'] ?? null,
                    'qty' => $i['qty'],
                    'price' => $i['price'],
                    'cost' => $i['cost'],
                ]);
            }

            // An order created already in Processing (the WooCommerce
            // importer maps Woo 'processing' to our 'Processing') never
            // passes through approve(), the only other place stock is
            // reserved - so reserve here, inside the same transaction,
            // through the shared exactly-once routine. Without this,
            // available stock is overstated and the lifecycle's release
            // (cancel) and ship decrements drive reserved_qty negative.
            // Deliberately NO shortage rejection (approve() has one):
            // intake must never fail - the order already exists on the
            // seller's own website - so reserve in full and let
            // availability tell the truth, even when it goes negative.
            if ($order->status === 'Processing') {
                $this->ensureReserved($order, $actor, "Reserved on create of {$order->invoice_no}");
            }

            // M19: spend the order's tokens now (idempotent per order).
            $this->tokens->deductForOrder($order);

            $order->logHistory(null, $order->status, $actor, 'Order created');
            $order->logActivity('system', "Order created via {$createdVia}", $actor);
            $verdictText = $check['verdict'] === 'safe' ? "Fraud check - Safe (success rate {$check['rate']}%)"
                : ($check['verdict'] === 'risky' ? "Fraud check - Risky (success rate ".($check['rate'] ?? 0)."%) - {$check['note']}" : 'Fraud check - Not checked (no delivery history)');
            $order->logActivity('system', $verdictText, $actor);

            $fresh = $order->fresh(['items']);
            app(\App\Services\FinanceService::class)->syncOrderAdvance($fresh, $actor);
            if ($check['network_pending'] ?? false) {
                // Queued on the database connection inside this transaction,
                // so the job becomes visible exactly when the order commits.
                \App\Jobs\RefreshFraudCheckJob::dispatch($order->id);
            }
            try {
                // The thank-you flow sends an SMS through the seller's
                // gateway (external HTTP) — queued, never synchronous.
                \App\Jobs\OrderCreatedAutomationJob::dispatch($fresh->id);
            } catch (\Throwable) {
                // Marketing must never break order intake.
            }
            try {
                app(\App\Services\StageService::class)->noteOrderCreated($fresh);
            } catch (\Throwable) {
                // Sales Growth staging must never break order intake.
            }
            try {
                // Auto-approve: when the intake snapshot is already final
                // and the customer clears the shop's minimum success rate,
                // the order approves itself here. When the network figure
                // is still pending, RefreshFraudCheckJob evaluates again
                // once it lands. Never breaks intake.
                app(\App\Services\AutoApproveService::class)->evaluate($fresh);
            } catch (\Throwable) {
                // Auto-approve must never break order intake.
            }
            // Keep the returned model truthful if auto-approve just moved it.
            $fresh->refresh();

            return $fresh;
        });
    }

    /** Settings → Advanced: "Disable Stock Management" — orders flow without touching stock. */
    private function stockOff(int $businessId): bool
    {
        return (bool) $this->settings->bizGet($businessId, "inventory.{$businessId}.stock_management_disabled", 'inventory.stock_management_disabled', false);
    }

    /**
     * The variant an order line refers to, resolved from the line's
     * stored variant_id or - for intake paths that only carry the name
     * (chat orders, older rows) - by matching the name on the product.
     * $item may be an OrderItem or a plain intake array.
     */
    private function variantForItem(Product $product, $item): ?ProductVariant
    {
        $vid = is_array($item) ? ($item['variant_id'] ?? null) : ($item->variant_id ?? null);
        if ($vid) {
            $variant = $product->variants()->find((int) $vid);
            if ($variant) {
                return $variant;
            }
        }
        $name = is_array($item) ? ($item['variant_name'] ?? null) : ($item->variant_name ?? null);
        if ($name) {
            return $product->variants()->where('name', $name)->first();
        }

        return null;
    }

    /**
     * The per-unit cost an order line snapshots at creation (Round 6,
     * P7/P8). Finance P&L reads order_items.cost, so this snapshot IS
     * the booked product cost:
     * - a variant line books the VARIANT's cost (its own row carries it;
     *   an unset variant cost falls back to the product's, mirroring
     *   ProductVariant::effectivePrice());
     * - a combo line books the sum of its components' costs (component
     *   cost x recipe qty), expanded exactly the way stock moves - the
     *   combo's own cost field is not what the shop spends;
     * - anything else books the product's own cost.
     */
    public function lineUnitCost(Product $product, ?ProductVariant $variant): float
    {
        if ($variant) {
            return $variant->cost !== null ? (float) $variant->cost : (float) $product->cost;
        }
        if ($product->type === 'combo') {
            $sum = 0.0;
            foreach ($this->expandLine($product, null, 1) as $t) {
                $sum += (float) ($t['variant']->cost ?? $t['product']->cost) * $t['qty'];
            }

            return $sum;
        }

        return (float) $product->cost;
    }

    /**
     * Expand one order line into the stock targets it actually moves
     * (Round 5, K-H1/K-H2): a variant line moves the VARIANT's pool; a
     * combo line moves each COMPONENT (recipe qty x line qty, combos
     * inside combos expand too); anything else moves the product
     * itself. A combo with no recipe falls back to its own row - there
     * is nothing else it could draw from. The combo's own stock row
     * never moves for a combo that has a recipe.
     *
     * @return array<int, array{product: Product, variant: ?ProductVariant, qty: int, key: string, label: string}>
     */
    private function expandLine(Product $product, ?ProductVariant $variant, int $qty, array $trail = []): array
    {
        if ($variant) {
            return [['product' => $product, 'variant' => $variant, 'qty' => $qty,
                'key' => 'v'.$variant->id, 'label' => "{$product->name} ({$variant->name})"]];
        }
        if ($product->type === 'combo' && ! in_array($product->id, $trail, true)) {
            $comps = ComboComponent::where('business_id', $product->business_id)
                ->where('combo_product_id', $product->id)->get();
            if ($comps->isNotEmpty()) {
                $out = [];
                foreach ($comps as $c) {
                    $cp = Product::where('business_id', $product->business_id)->find($c->component_product_id);
                    if (! $cp) {
                        continue;
                    }
                    $out = array_merge($out, $this->expandLine($cp, null, $qty * max(1, (int) $c->qty), [...$trail, $product->id]));
                }
                if ($out) {
                    return $out;
                }
            }
        }

        return [['product' => $product, 'variant' => null, 'qty' => $qty,
            'key' => 'p'.$product->id, 'label' => $product->name]];
    }

    /**
     * The availability a stock target draws from: a variant stands
     * alone on its own stock; any other target is the product itself,
     * judged on Product::available() (a combo target never reaches
     * here - expandLine already turned it into its components). Stock
     * Link / Shared Inventory pools were removed on the owner's order
     * (2026-11-24): one product, one listing, its own stock.
     *
     * @return array{0: string, 1: int} target key, available qty
     */
    private function targetPool(array $target): array
    {
        if ($target['variant']) {
            $v = $target['variant'];

            return ['v'.$v->id, (int) $v->stock_qty - (int) $v->reserved_qty];
        }

        return ['p'.$target['product']->id, $target['product']->available()];
    }

    /**
     * Shortage lines for already-expanded stock targets: needs are
     * summed per target first, so two lines drawing on the same
     * target (two combos sharing a component, the same product on
     * two lines) are judged together, never one by one.
     */
    private function shortagesForTargets(int $businessId, array $targets): array
    {
        if ($this->stockOff($businessId)) {
            return [];
        }
        $need = [];
        $label = [];
        $available = [];
        foreach ($targets as $t) {
            [$targetKey, $targetAvailable] = $this->targetPool($t);
            $need[$targetKey] = ($need[$targetKey] ?? 0) + $t['qty'];
            $label[$targetKey] ??= $t['label'];
            $available[$targetKey] = $targetAvailable;
        }
        $short = [];
        foreach ($need as $targetKey => $qty) {
            if ($qty > $available[$targetKey]) {
                $short[] = "{$label[$targetKey]}: need {$qty}, available {$available[$targetKey]}";
            }
        }

        return $short;
    }

    /**
     * Owner rule (2026-11-19): an order may not be approved beyond available
     * stock. Returns one line per short target; empty array = the order can
     * be approved. Free-text items (no product) cannot be stock-checked
     * and are skipped. Availability is target-aware: a combo line is
     * judged on its components, a variant line on the variant, and any
     * other line on the product's own stock (see expandLine/targetPool).
     */
    public function shortagesForItems(int $businessId, array $items): array
    {
        if ($this->stockOff($businessId)) {
            return [];
        }
        $targets = [];
        foreach ($items as $i) {
            $pid = (int) ($i['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $product = Product::where('business_id', $businessId)->find($pid);
            if (! $product) {
                continue;
            }
            $variant = $this->variantForItem($product, $i);
            $targets = array_merge($targets, $this->expandLine($product, $variant, (int) ($i['qty'] ?? 1)));
        }

        return $this->shortagesForTargets($businessId, $targets);
    }

    /** Stock shortages for an existing order's items (see shortagesForItems). */
    public function stockShortages(Order $order): array
    {
        return $this->shortagesForItems($order->business_id,
            $order->items->map(fn ($i) => ['product_id' => $i->product_id, 'qty' => $i->qty,
                'variant_id' => $i->variant_id, 'variant_name' => $i->variant_name])->all());
    }

    /**
     * Shortages for adding extra lines to an existing order (Add Item):
     * the order's current items plus the new lines, judged net of what
     * the order already holds - the same netting approve() applies, so
     * adding an unrelated product is never refused over stock this order
     * already reserved for itself (R5).
     */
    public function shortagesForAdditionalItems(Order $order, array $extraItems): array
    {
        if ($this->stockOff($order->business_id)) {
            return [];
        }
        $held = $this->heldQuantities($order);
        $needTargets = [];
        $lines = $order->items->map(fn ($i) => ['product_id' => $i->product_id, 'qty' => $i->qty,
            'variant_id' => $i->variant_id, 'variant_name' => $i->variant_name])->all();
        foreach (array_merge($lines, $extraItems) as $i) {
            $pid = (int) ($i['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $product = Product::where('business_id', $order->business_id)->find($pid);
            if (! $product) {
                continue;
            }
            $variant = $this->variantForItem($product, $i);
            foreach ($this->expandLine($product, $variant, (int) ($i['qty'] ?? 1)) as $t) {
                $already = $held[$t['key']] ?? 0;
                $use = min($already, $t['qty']);
                $held[$t['key']] = $already - $use;
                if ($t['qty'] - $use > 0) {
                    $needTargets[] = [...$t, 'qty' => $t['qty'] - $use];
                }
            }
        }

        return $this->shortagesForTargets($order->business_id, $needTargets);
    }

    /**
     * Shortages for a status move INTO Processing (S-F1): the same
     * discipline approve() applies, judged on exactly the coverage
     * ensureReserved() will use - per expanded target, the units this
     * order already holds and the units it already shipped (and has not
     * taken back) count as covered; only the uncovered shortfall can be
     * short. Mirrors ensureReserved()'s per-target math line for line,
     * so the check and the reservation can never disagree.
     */
    private function shortagesForReserveMove(Order $order): array
    {
        if ($this->stockOff($order->business_id)) {
            return [];
        }
        $held = $this->heldQuantities($order);
        $shipped = $this->shippedQuantities($order);
        $needTargets = [];
        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }
            $product = Product::where('business_id', $order->business_id)->find($item->product_id);
            if (! $product) {
                continue;
            }
            $variant = $this->variantForItem($product, $item);
            foreach ($this->expandLine($product, $variant, (int) $item->qty) as $t) {
                $already = $held[$t['key']] ?? 0;
                $held[$t['key']] = max(0, $already - $t['qty']);
                $gone = min(max(0, $t['qty'] - $already), $shipped[$t['key']] ?? 0);
                $shipped[$t['key']] = ($shipped[$t['key']] ?? 0) - $gone;
                $shortfall = $t['qty'] - $already - $gone;
                if ($shortfall > 0) {
                    $needTargets[] = [...$t, 'qty' => $shortfall];
                }
            }
        }

        return $this->shortagesForTargets($order->business_id, $needTargets);
    }

    /** Approve: fraud re-check -> stock reserve -> Processing -> Processed By. */
    public function approve(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            // Concurrency (Hunt Z1-6): the exactly-once story rested on
            // the caller's copy being fresh and on the storage engine
            // serialising writers. Lock the order row and re-read it
            // inside this transaction: a second concurrent approve
            // waits on the lock, then sees the post-approve state and
            // no-ops here - no second reservation, no second history
            // row - instead of re-running the whole path on a stale
            // Pending copy. (Callers only ever approve Pending /
            // Incomplete orders; anything else under the lock is the
            // winner's finished work.)
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();
            if ($locked) {
                $order->setRawAttributes($locked->getAttributes());
                $order->syncOriginal();
                if (! in_array($order->status, ['Pending', 'Incomplete'], true)) {
                    return $order;
                }
            }
            // Only the units this order does not already hold can be short:
            // a repeat approve() must not count its own reservation against
            // itself. A first approval (nothing held) gets the identical
            // full shortage check as before. The accounting runs on the
            // expanded stock targets (components for a combo line, the
            // variant for a variant line), matching ensureReserved().
            $held = $this->heldQuantities($order);
            $needTargets = [];
            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $product = Product::where('business_id', $order->business_id)->find($item->product_id);
                if (! $product) {
                    continue;
                }
                $variant = $this->variantForItem($product, $item);
                foreach ($this->expandLine($product, $variant, (int) $item->qty) as $t) {
                    $already = $held[$t['key']] ?? 0;
                    $use = min($already, $t['qty']);
                    $held[$t['key']] = $already - $use;
                    if ($t['qty'] - $use > 0) {
                        $needTargets[] = [...$t, 'qty' => $t['qty'] - $use];
                    }
                }
            }
            $short = $this->shortagesForTargets($order->business_id, $needTargets);
            if ($short) {
                throw ValidationException::withMessages(['stock' => 'Not enough stock - '.implode('; ', $short)]);
            }
            $check = $this->fraud->forPhone($order->business_id, $order->customer_phone);
            $order->fraud_verdict = $check['verdict'];
            $order->fraud_rate = $check['rate'];
            $order->fraud_note = $check['note'];

            // Exactly once: tops up only what the order does not already
            // hold, so approve() is safe to call on an order that intake
            // auto-approval (or an earlier approve) already reserved.
            $this->ensureReserved($order, $actor, "Reserved on approve of {$order->invoice_no}");

            $from = $order->status;
            $order->status = 'Processing';
            $order->processing_status = 'Processing';
            $order->processed_by = $actor->id;
            $order->approved_at = now();
            $order->save();

            $order->logHistory($from, 'Processing', $actor, 'Approved');
            $order->logActivity('action', "Approved by {$actor->name} - {$from} → Processing", $actor);

            $this->pushStockToWoo($order);

            return $order;
        });
    }

    /** Move the lifecycle status, handling stock release/return on the way. */
    public function changeStatus(Order $order, string $to, User $actor, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $to, $actor, $reason) {
            // Z1-5 (engine-correct): judge the transition from the row
            // as it stands NOW, under its lock. A caller holding a
            // stale copy (loaded as Processing while another request
            // already shipped the order) must see the true current
            // status, so the same-state no-op below and every
            // exactly-once stock block below decide on the real
            // transition, not on a memory.
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();
            if ($locked) {
                $order->setRawAttributes($locked->getAttributes());
                $order->syncOriginal();
            }
            $from = $order->status;

            // R9: the target must be a real order status. Every write
            // path (Super Edit included) funnels through here, so this
            // is the choke point: an unknown string would strand the
            // order outside every tab, filter and count.
            if (! in_array($to, array_merge(Order::STATUSES, Order::EXTRA_STATUSES), true)) {
                throw ValidationException::withMessages(['status' => __('That is not a valid order status.')]);
            }

            // Same-state no-op (Hunt Z1-5): moving an order to the
            // status it already holds changes nothing, and must DO
            // nothing - the stock blocks below were already guarded
            // against it, but the status save, the history row and the
            // Shipped/Delivered automation hooks (a paid customer SMS
            // each) ran unconditionally, so a double-click, a stale
            // tab re-save or a manual ship overlapping a courier
            // webhook re-fired all of them. A real transition keeps
            // today's behaviour exactly.
            if ($from === $to) {
                return $order;
            }

            // M4: a delivered order's goods have left and its accounts are
            // settled - cancelling it would make the unit vanish from stock
            // accounting entirely. Goods that come back go through Return.
            if ($to === 'Cancelled' && $from === 'Delivered') {
                throw ValidationException::withMessages(['status' => __('A delivered order cannot be cancelled. If the goods came back, use Return instead.')]);
            }

            // M3: a status move INTO Processing (Super Edit, or any other
            // caller) must hold the same reservation the normal approve
            // flow holds - exactly once, via the shared routine. Moves
            // from Shipped/Delivered/Returned are excluded: that stock
            // already left or came back and must not be reserved again.
            if ($to === 'Processing' && $from !== 'Processing'
                && in_array($from, ['Pending', 'Incomplete', 'Cancelled', 'Partial', 'Pending Cancel', 'Pending Return'], true)) {
                // S-F1: the move must pass the SAME shortage discipline
                // approve() applies before it reserves anything - the old
                // code reserved in full with no check, so an oversize
                // order (stock 2, qty 10) reserved 10 and the later ship
                // drove stock_qty to -8. Only the units this order does
                // not already hold and has not already shipped can be
                // short (the exact coverage ensureReserved() uses), and
                // refusing here rolls the whole move back: status,
                // reservation and ledger stay untouched.
                $short = $this->shortagesForReserveMove($order);
                if ($short) {
                    throw ValidationException::withMessages(['status' => __('Not enough stock - ').implode('; ', $short)]);
                }
                $this->ensureReserved($order, $actor, "Reserved on approve of {$order->invoice_no}");
            }
            // M3: leaving a reserved state back to Pending releases the
            // hold - the goods never left. S-F2: the same release applies
            // to Incomplete, and to orders leaving legacy Partial (the
            // status is no longer selectable; these from-guards only keep
            // a pre-removal row's reservation accounting exact): a
            // reservation may only live in Processing/Partial (and the
            // Pending Cancel/Pending Return on-ramps, which keep it).
            if (in_array($from, ['Processing', 'Partial'], true) && in_array($to, ['Pending', 'Incomplete'], true)) {
                $this->releaseReservations($order, 'release_reserve', 'Released on status change');
            }
            // Cancelled (R1a): goods that already shipped come back onto
            // the shelf - the same restock a Return performs; without it
            // the shipped units vanished from stock accounting entirely
            // (the M4 failure, one status earlier). Whatever reservation
            // is still held (Processing, Pending Cancel, ...) is released.
            if ($to === 'Cancelled' && $from !== 'Cancelled') {
                $this->restockShippedUnits($order, $actor, 'Restocked on cancel of');
                $this->releaseReservations($order, 'release_reserve', 'Released on cancel');
            }
            // Returned: goods that shipped come back onto the same rows
            // they left (the variant's stock for a variant line, the
            // components for a combo line, the product otherwise). An
            // order that never shipped (Processing -> Pending Return ->
            // Returned) simply lets go of its reservation - no return_in
            // rows for stock that never left (R1a).
            if ($to === 'Returned' && $from !== 'Returned') {
                // Restock what actually shipped, then release whatever
                // reservation is still held (the mixed case: part
                // shipped, part still held - the old either/or left the
                // held remainder behind forever). Both halves are
                // ledger-exact, so a pure case runs only its own half.
                $this->restockShippedUnits($order, $actor, 'Returned on');
                $this->releaseReservations($order, 'release_reserve', 'Released on status change');
            }
            // M3: Processing -> Delivered consumes exactly like Processing
            // -> Shipped (a direct delivery never passed a Shipped state).
            // S-F2: the consume runs for a landing in Shipped/Delivered
            // from ANY origin state, not just Processing - a jump that
            // skips Processing (Pending -> Shipped, legacy Partial -> Shipped)
            // used to leave the goods on the books forever (and leak the
            // reservation). The body below already nets the units that
            // are already out per the ledger, so a repeat landing
            // (Shipped -> Delivered, or a round trip back) consumes
            // nothing twice.
            if (in_array($to, ['Shipped', 'Delivered'], true) && $from !== $to && ! $this->stockOff($order->business_id)) {
                $held = $this->heldQuantities($order);
                $shipped = $this->shippedQuantities($order);
                foreach ($order->items as $item) {
                    if (! $item->product_id) {
                        continue;
                    }
                    $product = Product::where('business_id', $order->business_id)->find($item->product_id);
                    if (! $product) {
                        continue;
                    }
                    // Consume per expanded target (variant pool for a
                    // variant line, components for a combo line): only
                    // the units that have not already left (R1b - a
                    // status round trip back through Processing must
                    // never consume the same goods twice), and only the
                    // reservation this order actually holds on that
                    // target, so reserved_qty can never be driven
                    // negative; the physical stock leaves in full.
                    $variant = $this->variantForItem($product, $item);
                    foreach ($this->expandLine($product, $variant, (int) $item->qty) as $t) {
                        $alreadyOut = min($t['qty'], $shipped[$t['key']] ?? 0);
                        $shipped[$t['key']] = ($shipped[$t['key']] ?? 0) - $alreadyOut;
                        $qty = $t['qty'] - $alreadyOut;
                        if ($qty <= 0) {
                            continue;
                        }
                        $row = $t['variant'] ?? $t['product'];
                        $cost = (float) ($t['variant']->cost ?? $t['product']->cost);
                        $prevStock = $row->stock_qty;
                        $consume = min($qty, $held[$t['key']] ?? 0);
                        $held[$t['key']] = ($held[$t['key']] ?? 0) - $consume;
                        if ($consume > 0) {
                            $row->decrement('reserved_qty', $consume);
                        }
                        $row->decrement('stock_qty', $qty);
                        if (! $t['variant']) {
                            $this->stock->syncDefaultBranchStock($t['product'], -(int) $qty);
                        }
                        StockLedger::create(['business_id' => $order->business_id, 'product_id' => $t['product']->id,
                            'variant_id' => $t['variant']?->id,
                            'order_id' => $order->id, 'type' => 'ship_out', 'qty' => $qty,
                            'prev_stock' => $prevStock, 'new_stock' => $prevStock - $qty,
                            'unit_cost' => $cost, 'value_impact' => -($qty * $cost),
                            'reference' => $order->invoice_no, 'user_id' => $actor->id,
                            'note' => ($to === 'Shipped' ? 'Shipped on ' : 'Delivered on ') . $order->invoice_no]);
                    }
                }
                // S-F2: once every unit is out, the order holds nothing -
                // release any reservation the ledger still shows for it
                // (a stale excess from a partial ship or a legacy double
                // reserve). Ledger-exact and a no-op in the normal case,
                // where the consume above already zeroed the hold.
                $this->releaseHeldReservations($order, 'Released on ship', $actor);
            }

            // Landing in the pipeline by a status move (Super Edit and
            // every other caller) stamps approved_at too - exactly once,
            // only when no earlier path stamped it (Round 6, P9).
            if ($order->approved_at === null && in_array($to, self::APPROVED_STATUSES, true)) {
                $order->approved_at = now();
            }
            $order->status = $to;
            if ($to === 'Cancelled') {
                $order->cancellation_reason = $reason;
            }
            $order->save();
            $order->logHistory($from, $to, $actor, $reason);
            $order->logActivity('action', "Status changed by {$actor->name} - {$from} → {$to}".($reason ? " ({$reason})" : ''), $actor);

            try {
                $auto = app(\App\Services\AutomationService::class);
                if ($to === 'Shipped') {
                    $auto->onShipped($order);
                } elseif ($to === 'Delivered') {
                    $auto->onDelivered($order);
                }
            } catch (\Throwable) {
                // Marketing must never break the lifecycle.
            }

            $this->pushStockToWoo($order);

            return $order;
        });
    }

    /**
     * Website integration: reserve/release/ship/return moves in approve()
     * and changeStatus() change available stock (stock_qty - reserved_qty)
     * directly on the product rows, bypassing StockService::adjust(), so
     * the WooCommerce stock push is queued from here too. The job no-ops
     * for shops without a Woo connection and skips unchanged quantities.
     */
    private function pushStockToWoo(Order $order): void
    {
        try {
            // The products whose available stock actually moved: the
            // line products plus every expanded target (a combo's
            // components), since the movements land on those rows.
            $productIds = collect();
            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $productIds->push((int) $item->product_id);
                $product = Product::where('business_id', $order->business_id)->find($item->product_id);
                if ($product) {
                    $variant = $this->variantForItem($product, $item);
                    foreach ($this->expandLine($product, $variant, (int) $item->qty) as $t) {
                        $productIds->push((int) $t['product']->id);
                    }
                }
            }
            foreach ($productIds->unique() as $productId) {
                \App\Jobs\Integrations\PushWooStockJob::dispatchDebounced((int) $order->business_id, (int) $productId);
            }
        } catch (\Throwable) {
            // The integration layer must never break the order lifecycle.
        }
    }

    /** Bangladesh's districts, longest names first for unambiguous matching. */
    private const DISTRICTS = [
        'Bandarban', 'Barguna', 'Barishal', 'Bhola', 'Bogura', 'Brahmanbaria', 'Chandpur', 'Chattogram',
        'Chuadanga', 'Comilla', "Cox's Bazar", 'Dhaka', 'Dinajpur', 'Faridpur', 'Feni', 'Gaibandha',
        'Gazipur', 'Gopalganj', 'Habiganj', 'Jamalpur', 'Jashore', 'Jhalokati', 'Jhenaidah', 'Joypurhat',
        'Khagrachari', 'Khulna', 'Kishoreganj', 'Kurigram', 'Kushtia', 'Lakshmipur', 'Lalmonirhat',
        'Madaripur', 'Magura', 'Manikganj', 'Meherpur', 'Moulvibazar', 'Munshiganj', 'Mymensingh',
        'Naogaon', 'Narail', 'Narayanganj', 'Narsingdi', 'Natore', 'Netrokona', 'Nilphamari', 'Noakhali',
        'Pabna', 'Panchagarh', 'Patuakhali', 'Pirojpur', 'Rajbari', 'Rajshahi', 'Rangamati', 'Rangpur',
        'Satkhira', 'Shariatpur', 'Sherpur', 'Sirajganj', 'Sunamganj', 'Sylhet', 'Tangail', 'Thakurgaon',
        'Chapai Nawabganj', 'Bagerhat', 'Barisal', 'Chittagong', 'Jessore', 'Nawabganj',
    ];

    /** Best-effort district from a free-text address (a name appearing in it). */
    /** The district an address text names, if any (shared: the external API's zone charge reads it too). */
    public static function deriveDistrict(?string $address): ?string
    {
        $address = trim((string) $address);
        if ($address === '') {
            return null;
        }
        $names = self::DISTRICTS;
        usort($names, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($names as $name) {
            if (stripos($address, $name) !== false) {
                return $name === 'Barisal' ? 'Barishal' : ($name === 'Chittagong' ? 'Chattogram' : ($name === 'Jessore' ? 'Jashore' : $name));
            }
        }

        return null;
    }

    /**
     * Quantities this order currently HOLDS per stock target, derived
     * from the stock ledger: reserves minus releases minus ship-outs.
     * The ledger is the only per-order stock record (it has no
     * order_item_id), so it is the source of truth for "does this order
     * already hold a reservation". Keys are the expandLine() target
     * keys: "p{product_id}" for a product pool (a combo's components
     * hold under their own product keys), "v{variant_id}" for a
     * variant pool. Legacy rows written before the exactly-once rule
     * show up here too - a double reserve reads as held > item qty and
     * is cleaned on release.
     *
     * @return array<string, int> target key => held qty
     */
    private function heldQuantities(Order $order): array
    {
        $held = [];
        $rows = StockLedger::where('order_id', $order->id)
            ->whereIn('type', ['reserve', 'release_reserve', 'ship_out'])
            ->selectRaw('product_id, variant_id, type, SUM(qty) as total')
            ->groupBy('product_id', 'variant_id', 'type')
            ->get();
        foreach ($rows as $row) {
            $key = $row->variant_id ? 'v'.(int) $row->variant_id : 'p'.(int) $row->product_id;
            $held[$key] = ($held[$key] ?? 0) + ($row->type === 'reserve' ? (int) $row->total : -(int) $row->total);
        }

        return array_map(fn ($q) => max(0, $q), $held);
    }

    /**
     * Quantities this order has physically OUT per stock target, per the
     * ledger: ship_outs minus return_ins, with the same target keys as
     * heldQuantities(). This is what makes status round trips safe
     * (R1b): units that already left - and have not come back - are
     * never reserved, shipped or restocked a second time.
     *
     * @return array<string, int> target key => net shipped qty
     */
    private function shippedQuantities(Order $order): array
    {
        $shipped = [];
        $rows = StockLedger::where('order_id', $order->id)
            ->whereIn('type', ['ship_out', 'return_in'])
            ->selectRaw('product_id, variant_id, type, SUM(qty) as total')
            ->groupBy('product_id', 'variant_id', 'type')
            ->get();
        foreach ($rows as $row) {
            $key = $row->variant_id ? 'v'.(int) $row->variant_id : 'p'.(int) $row->product_id;
            $shipped[$key] = ($shipped[$key] ?? 0) + ($row->type === 'ship_out' ? (int) $row->total : -(int) $row->total);
        }

        return array_map(fn ($q) => max(0, $q), $shipped);
    }

    /**
     * Put back the units this order actually shipped and has not yet
     * returned (per shippedQuantities(), per expanded target), writing
     * return_in rows on the same rows the goods left. Returns true when
     * anything was restocked. Shared by Return and Cancel-after-ship
     * (R1a): for both, the goods are physically back with the seller, so
     * a plain reservation release would leave them unaccounted.
     */
    private function restockShippedUnits(Order $order, User $actor, string $notePrefix): bool
    {
        if ($this->stockOff($order->business_id)) {
            return false;
        }
        $shipped = $this->shippedQuantities($order);
        $restocked = false;
        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }
            $product = Product::where('business_id', $order->business_id)->find($item->product_id);
            if (! $product) {
                continue;
            }
            $variant = $this->variantForItem($product, $item);
            foreach ($this->expandLine($product, $variant, (int) $item->qty) as $t) {
                $qty = min($t['qty'], $shipped[$t['key']] ?? 0);
                $shipped[$t['key']] = ($shipped[$t['key']] ?? 0) - $qty;
                if ($qty <= 0) {
                    continue;
                }
                $row = $t['variant'] ?? $t['product'];
                $cost = (float) ($t['variant']->cost ?? $t['product']->cost);
                $prevStock = $row->stock_qty;
                $row->increment('stock_qty', $qty);
                if (! $t['variant']) {
                    $this->stock->syncDefaultBranchStock($t['product'], (int) $qty);
                }
                StockLedger::create(['business_id' => $order->business_id, 'product_id' => $t['product']->id,
                    'variant_id' => $t['variant']?->id,
                    'order_id' => $order->id, 'type' => 'return_in', 'qty' => $qty,
                    'prev_stock' => $prevStock, 'new_stock' => $prevStock + $qty,
                    'unit_cost' => $cost, 'value_impact' => $qty * $cost,
                    'reference' => $order->invoice_no, 'user_id' => $actor->id,
                    'note' => "{$notePrefix} {$order->invoice_no}"]);
                $restocked = true;
            }
        }

        return $restocked;
    }

    /** Write one reserve/release ledger row for a target and move its reserved_qty. */
    private function moveReservation(Order $order, array $target, int $qty, string $type, ?User $actor, string $note): void
    {
        $row = $target['variant'] ?? $target['product'];
        if ($type === 'reserve') {
            $row->increment('reserved_qty', $qty);
        } else {
            $row->decrement('reserved_qty', $qty);
        }
        StockLedger::create(['business_id' => $order->business_id, 'product_id' => $target['product']->id,
            'variant_id' => $target['variant']?->id,
            'order_id' => $order->id, 'type' => $type, 'qty' => $qty,
            'prev_stock' => $row->stock_qty, 'new_stock' => $row->stock_qty,
            'unit_cost' => $target['product']->cost, 'value_impact' => 0,
            'reference' => $order->invoice_no, 'user_id' => $actor?->id ?? auth()->id(),
            'note' => $note]);
    }

    /** Rebuild a stock target from its held-quantities key (legacy remainder releases). */
    private function targetForKey(Order $order, string $key): ?array
    {
        if (str_starts_with($key, 'v')) {
            $variant = ProductVariant::where('business_id', $order->business_id)->find((int) substr($key, 1));
            $product = $variant ? Product::where('business_id', $order->business_id)->find($variant->product_id) : null;

            return $product ? ['product' => $product, 'variant' => $variant, 'qty' => 0, 'key' => $key, 'label' => $product->name] : null;
        }
        $product = Product::where('business_id', $order->business_id)->find((int) substr($key, 1));

        return $product ? ['product' => $product, 'variant' => null, 'qty' => 0, 'key' => $key, 'label' => $product->name] : null;
    }

    /**
     * Reserve stock for an order EXACTLY ONCE per item (Round 3, H1).
     * Every reservation in the system goes through here - create(),
     * approve(), add-item and status moves into Processing - and each call
     * only tops up the shortfall between what the order's items need and
     * what the ledger says the order already holds, so a repeat call (a
     * second approve(), an auto-approve followed by a manual approve)
     * writes nothing. The item cost snapshot create()/approve() always
     * took still happens; when stock management is off, no reservation is
     * written. Deliberately NO shortage rejection here (approve() checks
     * before calling): intake paths must never fail on stock.
     */
    public function ensureReserved(Order $order, ?User $actor, string $note): void
    {
        $held = $this->heldQuantities($order);
        $shipped = $this->shippedQuantities($order);
        $off = $this->stockOff($order->business_id);
        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }
            $product = Product::where('business_id', $order->business_id)->find($item->product_id);
            if (! $product) {
                continue;
            }
            $variant = $this->variantForItem($product, $item);
            // Snapshot the line's true unit cost for the profit engine -
            // the SAME definition create() uses (Round 6, P7/P8): the
            // variant's cost for a variant line, the component sum for a
            // combo line, never blindly the parent product's cost (that
            // stomp booked every combo at 0 and every variant at the
            // parent's cost).
            $item->cost = $this->lineUnitCost($product, $variant);
            $item->save();
            if ($off) {
                continue;
            }
            // Reserve per expanded target: the variant's pool for a
            // variant line, the components for a combo line, the
            // product itself otherwise - each topped up only by what
            // this order does not already hold on that target.
            foreach ($this->expandLine($product, $variant, (int) $item->qty) as $t) {
                // Units this order already shipped (and has not taken
                // back) count as covered: they are gone for good, so a
                // status round trip back into Processing must not
                // reserve the same goods a second time (R1b) - the old
                // held-only math read them as "holds nothing" and
                // reserved in full, then shipped in full again.
                $already = $held[$t['key']] ?? 0;
                $held[$t['key']] = max(0, $already - $t['qty']);
                $gone = min(max(0, $t['qty'] - $already), $shipped[$t['key']] ?? 0);
                $shipped[$t['key']] = ($shipped[$t['key']] ?? 0) - $gone;
                $shortfall = $t['qty'] - $already - $gone;
                if ($shortfall <= 0) {
                    continue;
                }
                $this->moveReservation($order, $t, $shortfall, 'reserve', $actor, $note);
            }
        }
    }

    /**
     * Release whatever reservation this order actually holds, per the
     * ledger - never more (a repeat call is a no-op, so reserved_qty is
     * never driven negative) and never less (a legacy double reserve is
     * fully cleaned up, so a dead order holds nothing). $notePrefix is the
     * existing note wording without the invoice, e.g. 'Released on cancel'.
     */
    public function releaseHeldReservations(Order $order, string $notePrefix, ?User $actor = null): void
    {
        if ($this->stockOff($order->business_id)) {
            return;
        }
        $held = $this->heldQuantities($order);
        $targets = [];
        $release = function (array $target, int $qty) use ($order, $notePrefix, $actor) {
            $this->moveReservation($order, $target, $qty, 'release_reserve', $actor, "{$notePrefix} on {$order->invoice_no}");
        };
        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }
            $product = Product::where('business_id', $order->business_id)->find($item->product_id);
            if (! $product) {
                continue;
            }
            // Release on the same targets the reservation was taken on:
            // the variant's pool for a variant line, the components for
            // a combo line, the product itself otherwise.
            $variant = $this->variantForItem($product, $item);
            foreach ($this->expandLine($product, $variant, (int) $item->qty) as $t) {
                $targets[$t['key']] = $t;
                $qty = min($t['qty'], $held[$t['key']] ?? 0);
                $held[$t['key']] = ($held[$t['key']] ?? 0) - $qty;
                if ($qty > 0) {
                    $release($t, $qty);
                }
            }
        }
        // Legacy over-reserve: held units beyond the items' quantities
        // (written by the pre-fix double reserve) still belong to this
        // order - release the remainder so nothing leaks.
        foreach ($held as $key => $left) {
            if ($left <= 0) {
                continue;
            }
            $target = $targets[$key] ?? $this->targetForKey($order, $key);
            if ($target) {
                $release($target, $left);
            }
        }
    }

    private function releaseReservations(Order $order, string $type, string $note): void
    {
        $this->releaseHeldReservations($order, $note, auth()->user());
    }
}
