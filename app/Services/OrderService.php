<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The order book's core operations: intake, approve, lifecycle moves, stock. */
class OrderService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SuccessRateService $fraud,
    ) {}

    public function nextInvoice(int $businessId): string
    {
        $slug = (string) $this->settings->get('business.invoice_slug', 'OB');
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
                    'qty' => (int) ($i['qty'] ?? 1),
                    'price' => (float) ($i['price'] ?? $product?->price ?? 0),
                    'cost' => $product ? (float) $product->cost : (float) ($i['cost'] ?? 0),
                ];
            });
            $subtotal = $items->sum(fn ($i) => $i['price'] * $i['qty']);
            $discount = (float) ($data['discount'] ?? 0);
            $delivery = (float) ($data['delivery_charge'] ?? 0);
            $advance = (float) ($data['advance'] ?? 0);

            $check = $this->fraud->forPhone($businessId, $phone);

            $order = Order::create([
                'business_id' => $businessId,
                'invoice_no' => $data['invoice_no'] ?? $this->nextInvoice($businessId),
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
                    'qty' => $i['qty'],
                    'price' => $i['price'],
                    'cost' => $i['cost'],
                ]);
            }

            $order->logHistory(null, $order->status, $actor, 'Order created');
            $order->logActivity('system', "Order created via {$createdVia}", $actor);
            $verdictText = $check['verdict'] === 'safe' ? "Fraud check — Safe (success rate {$check['rate']}%)"
                : ($check['verdict'] === 'risky' ? "Fraud check — Risky (success rate ".($check['rate'] ?? 0)."%) — {$check['note']}" : 'Fraud check — Not checked (no delivery history)');
            $order->logActivity('system', $verdictText, $actor);

            return $order->fresh(['items']);
        });
    }

    /** Approve: fraud re-check -> stock reserve -> Processing -> Processed By. */
    public function approve(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
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
                $product->increment('reserved_qty', $item->qty);
                StockLedger::create(['business_id' => $order->business_id, 'product_id' => $product->id,
                    'order_id' => $order->id, 'type' => 'reserve', 'qty' => $item->qty,
                    'note' => "Reserved on approve of {$order->invoice_no}"]);
            }

            $from = $order->status;
            $order->status = 'Processing';
            $order->processing_status = 'Processing';
            $order->processed_by = $actor->id;
            $order->approved_at = now();
            $order->save();

            $order->logHistory($from, 'Processing', $actor, 'Approved');
            $order->logActivity('action', "Approved by {$actor->name} — {$from} → Processing", $actor);

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
            if ($to === 'Returned' && in_array($from, ['Shipped', 'Delivered'], true)) {
                foreach ($order->items as $item) {
                    if (! $item->product_id) {
                        continue;
                    }
                    $product = Product::where('business_id', $order->business_id)->find($item->product_id);
                    if (! $product) {
                        continue;
                    }
                    $product->increment('stock_qty', $item->qty);
                    StockLedger::create(['business_id' => $order->business_id, 'product_id' => $product->id,
                        'order_id' => $order->id, 'type' => 'return_in', 'qty' => $item->qty,
                        'note' => "Returned on {$order->invoice_no}"]);
                }
            }
            if ($to === 'Shipped' && $from === 'Processing') {
                foreach ($order->items as $item) {
                    if (! $item->product_id) {
                        continue;
                    }
                    $product = Product::where('business_id', $order->business_id)->find($item->product_id);
                    if (! $product) {
                        continue;
                    }
                    $product->decrement('reserved_qty', $item->qty);
                    $product->decrement('stock_qty', $item->qty);
                    StockLedger::create(['business_id' => $order->business_id, 'product_id' => $product->id,
                        'order_id' => $order->id, 'type' => 'ship_out', 'qty' => $item->qty,
                        'note' => "Shipped on {$order->invoice_no}"]);
                }
            }

            $order->status = $to;
            if ($to === 'Cancelled') {
                $order->cancellation_reason = $reason;
            }
            $order->save();
            $order->logHistory($from, $to, $actor, $reason);
            $order->logActivity('action', "Status changed by {$actor->name} — {$from} → {$to}".($reason ? " ({$reason})" : ''), $actor);

            return $order;
        });
    }

    private function releaseReservations(Order $order, string $type, string $note): void
    {
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
                'note' => "{$note} on {$order->invoice_no}"]);
        }
    }
}
