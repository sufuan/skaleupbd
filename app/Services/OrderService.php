<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The order book's core operations: intake, approve, lifecycle moves, stock. */
class OrderService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SuccessRateService $fraud,
        private readonly TokenService $tokens,
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
                $data['district'] = $this->deriveDistrict($data['customer_address'] ?? null);
            }
            $phone = PhoneNormalizer::normalize($data['customer_phone'] ?? '');
            $customer = $this->customerFor($businessId, $phone, [
                'name' => $data['customer_name'] ?? null, 'email' => $data['customer_email'] ?? null,
                'address' => $data['customer_address'] ?? null, 'district' => $data['district'] ?? null, 'area' => $data['area'] ?? null,
            ]);

            $items = collect($data['items'] ?? [])->map(function ($i) use ($businessId) {
                $product = ! empty($i['product_id']) ? Product::where('business_id', $businessId)->find($i['product_id']) : null;
                return [
                    'product' => $product,
                    'product_id' => $product?->id,
                    'name' => $i['name'] ?? $product?->name ?? 'Item',
                    'sku' => $i['sku'] ?? $product?->sku,
                    'variant_name' => $i['variant_name'] ?? null,
                    'qty' => (int) ($i['qty'] ?? 1),
                    'price' => (float) ($i['price'] ?? $product?->price ?? 0),
                    'cost' => $product ? (float) $product->cost : (float) ($i['cost'] ?? 0),
                ];
            });
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
                'preorder_reason' => $data['preorder_reason'] ?? null,
                'notify_date' => $data['notify_date'] ?? null,
                'external_ref' => $data['external_ref'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
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
                    'variant_name' => $i['variant_name'] ?? null,
                    'qty' => $i['qty'],
                    'price' => $i['price'],
                    'cost' => $i['cost'],
                ]);
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
     * Owner rule (2026-11-19): an order may not be approved beyond available
     * stock (stock_qty − reserved_qty). Returns one line per short product;
     * empty array = the order can be approved. Free-text items (no product)
     * cannot be stock-checked and are skipped.
     */
    public function shortagesForItems(int $businessId, array $items): array
    {
        if ($this->stockOff($businessId)) {
            return [];
        }
        $need = [];
        foreach ($items as $i) {
            $pid = (int) ($i['product_id'] ?? 0);
            if ($pid > 0) {
                $need[$pid] = ($need[$pid] ?? 0) + (int) ($i['qty'] ?? 1);
            }
        }
        $short = [];
        foreach ($need as $pid => $qty) {
            $product = Product::where('business_id', $businessId)->find($pid);
            if (! $product) {
                continue;
            }
            $available = (int) $product->stock_qty - (int) $product->reserved_qty;
            if ($qty > $available) {
                $short[] = "{$product->name}: need {$qty}, available {$available}";
            }
        }

        return $short;
    }

    /** Stock shortages for an existing order's items (see shortagesForItems). */
    public function stockShortages(Order $order): array
    {
        return $this->shortagesForItems($order->business_id,
            $order->items->map(fn ($i) => ['product_id' => $i->product_id, 'qty' => $i->qty])->all());
    }

    /** Approve: fraud re-check -> stock reserve -> Processing -> Processed By. */
    public function approve(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $short = $this->stockShortages($order);
            if ($short) {
                throw ValidationException::withMessages(['stock' => 'Not enough stock - '.implode('; ', $short)]);
            }
            $check = $this->fraud->forPhone($order->business_id, $order->customer_phone);
            $order->fraud_verdict = $check['verdict'];
            $order->fraud_rate = $check['rate'];
            $order->fraud_note = $check['note'];

            foreach ($order->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $product = Product::where('business_id', $order->business_id)->find($item->product_id);
                if (! $product) {
                    continue;
                }
                // snapshot the current average cost for the profit engine
                $item->cost = $product->cost;
                $item->save();
                if ($this->stockOff($order->business_id)) {
                    continue;
                }
                $product->increment('reserved_qty', $item->qty);
                StockLedger::create(['business_id' => $order->business_id, 'product_id' => $product->id,
                    'order_id' => $order->id, 'type' => 'reserve', 'qty' => $item->qty,
                    'prev_stock' => $product->stock_qty, 'new_stock' => $product->stock_qty,
                    'unit_cost' => $product->cost, 'value_impact' => 0,
                    'reference' => $order->invoice_no, 'user_id' => $actor->id,
                    'note' => "Reserved on approve of {$order->invoice_no}"]);
            }

            $from = $order->status;
            $order->status = 'Processing';
            $order->processing_status = 'Processing';
            $order->processed_by = $actor->id;
            $order->approved_at = now();
            $order->save();

            $order->logHistory($from, 'Processing', $actor, 'Approved');
            $order->logActivity('action', "Approved by {$actor->name} - {$from} → Processing", $actor);

            return $order;
        });
    }

    /** Move the lifecycle status, handling stock release/return on the way. */
    public function changeStatus(Order $order, string $to, User $actor, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $to, $actor, $reason) {
            $from = $order->status;

            if ($to === 'Cancelled' && $from === 'Processing') {
                $this->releaseReservations($order, 'release_reserve', 'Released on cancel');
            }
            if ($to === 'Returned' && in_array($from, ['Shipped', 'Delivered'], true) && ! $this->stockOff($order->business_id)) {
                foreach ($order->items as $item) {
                    if (! $item->product_id) {
                        continue;
                    }
                    $product = Product::where('business_id', $order->business_id)->find($item->product_id);
                    if (! $product) {
                        continue;
                    }
                    $prevStock = $product->stock_qty;
                    $product->increment('stock_qty', $item->qty);
                    StockLedger::create(['business_id' => $order->business_id, 'product_id' => $product->id,
                        'order_id' => $order->id, 'type' => 'return_in', 'qty' => $item->qty,
                        'prev_stock' => $prevStock, 'new_stock' => $prevStock + $item->qty,
                        'unit_cost' => $product->cost, 'value_impact' => $item->qty * (float) $product->cost,
                        'reference' => $order->invoice_no, 'user_id' => $actor->id,
                        'note' => "Returned on {$order->invoice_no}"]);
                }
            }
            if ($to === 'Shipped' && $from === 'Processing' && ! $this->stockOff($order->business_id)) {
                foreach ($order->items as $item) {
                    if (! $item->product_id) {
                        continue;
                    }
                    $product = Product::where('business_id', $order->business_id)->find($item->product_id);
                    if (! $product) {
                        continue;
                    }
                    $prevStock = $product->stock_qty;
                    $product->decrement('reserved_qty', $item->qty);
                    $product->decrement('stock_qty', $item->qty);
                    StockLedger::create(['business_id' => $order->business_id, 'product_id' => $product->id,
                        'order_id' => $order->id, 'type' => 'ship_out', 'qty' => $item->qty,
                        'prev_stock' => $prevStock, 'new_stock' => $prevStock - $item->qty,
                        'unit_cost' => $product->cost, 'value_impact' => -($item->qty * (float) $product->cost),
                        'reference' => $order->invoice_no, 'user_id' => $actor->id,
                        'note' => "Shipped on {$order->invoice_no}"]);
                }
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

            return $order;
        });
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
    private function deriveDistrict(?string $address): ?string
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

    private function releaseReservations(Order $order, string $type, string $note): void
    {
        if ($this->stockOff($order->business_id)) {
            return;
        }
        foreach ($order->items as $item) {
            if (! $item->product_id) {
                continue;
            }
            $product = Product::where('business_id', $order->business_id)->find($item->product_id);
            if (! $product) {
                continue;
            }
            $product->decrement('reserved_qty', $item->qty);
            StockLedger::create(['business_id' => $order->business_id, 'product_id' => $product->id,
                'order_id' => $order->id, 'type' => $type, 'qty' => $item->qty,
                'prev_stock' => $product->stock_qty, 'new_stock' => $product->stock_qty,
                'unit_cost' => $product->cost, 'value_impact' => 0,
                'reference' => $order->invoice_no, 'user_id' => auth()->id(),
                'note' => "{$note} on {$order->invoice_no}"]);
        }
    }
}
