<?php

// Goes in: app/Services/Integrations/WooCommerceOrderImporter.php (new file).
// Converts a raw WooCommerce order payload into our system via the existing OrderService.
//
// Called by:
//   - ProcessWooWebhookJob (for inbound webhook events)
//   - WooReconciliationJob (for gap recovery during stage 7)
//
// Rules:
//   - Zero-points check happens BEFORE calling this. At zero points nothing is imported.
//   - Products are matched by external_id; unmatched products are stored with name + price as-is.
//   - An unmatched product NEVER blocks the order - it is imported with the name from WooCommerce.
//   - Phone numbers are normalised by OrderService (PhoneNormalizer) on the create path.
//   - Points are deducted by the token gate inside OrderService::create (unchanged).
//   - external_orders row is upserted with the WooCommerce order ID.

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Services\OrderService;
use Illuminate\Support\Facades\DB;

class WooCommerceOrderImporter
{
    public function __construct(
        private readonly IntegrationHost $host,
        private readonly OrderService $orders,
    ) {
    }

    /**
     * Import (or update) one WooCommerce order into our system.
     *
     * @param  array  $statusMap  ['processing' => 'Processing', 'on-hold' => 'Pending', ...]
     * @return int|null  our order id, or null when skipped
     */
    public function importOrder(IntegrationConnection $conn, array $wooOrder, array $statusMap): ?int
    {
        $wooId = (int) ($wooOrder['id'] ?? 0);
        $wooStatus = (string) ($wooOrder['status'] ?? '');

        if ($wooId === 0) {
            return null;
        }

        // --- Status filter: only import statuses in the map
        $ourStatus = $statusMap[$wooStatus] ?? null;
        if (! $ourStatus) {
            return null; // e.g. woo "completed" is not imported
        }

        // --- Check for existing order
        $existing = DB::table('external_orders')
            ->where('connection_id', $conn->id)
            ->where('external_order_id', (string) $wooId)
            ->first(['order_id']);

        if ($existing) {
            // Order already in our system: the write-back path owns status
            // sync from here; just hand back the known id.
            return (int) $existing->order_id;
        }

        // --- Build products list (OrderService item shape)
        $items = $this->buildItems($conn, $wooOrder);

        // --- Build customer fields
        $billing = $wooOrder['billing'] ?? [];
        $customerName = trim(($billing['first_name'] ?? '').' '.($billing['last_name'] ?? ''));
        $customerPhone = (string) ($billing['phone'] ?? '');
        $address = trim(implode(', ', array_filter([
            $billing['address_1'] ?? '',
            $billing['address_2'] ?? '',
            $billing['city'] ?? '',
        ])));
        $district = (string) ($billing['state'] ?? $billing['city'] ?? '');

        // --- Create through the real OrderService: fraud snapshot, stock,
        // activity log, notification and the point deduction all happen
        // there, exactly as for every other order source. The call throws
        // InsufficientTokensException when the shop cannot afford the
        // order; the caller (job) records that as a failed event.
        $order = $this->orders->create((int) $conn->business_id, [
            'customer_name' => $customerName ?: 'WooCommerce customer',
            'customer_phone' => $customerPhone,
            'customer_address' => $address ?: null,
            'district' => $district ?: null,
            'source' => 'WEB',
            'site' => $conn->site_url,
            'status' => $ourStatus,
            'delivery_note' => (string) ($wooOrder['customer_note'] ?? '') ?: null,
            'discount' => (float) ($wooOrder['discount_total'] ?? 0),
            'delivery_charge' => (float) ($wooOrder['shipping_total'] ?? 0),
            'external_ref' => 'woo:'.$wooId,
            'idempotency_key' => 'woo:'.$conn->id.':'.$wooId,
            'items' => $items,
        ], null, 'WooCommerce');

        // --- Record in external_orders. Loop prevention: last_pushed_status
        // always holds a WooCommerce status string (that is what
        // WooCommerceWriteBackService::push stores and isEcho compares
        // against), so seed it with the Woo status this order arrived in:
        // pushing that same status back is then correctly a no-op.
        DB::table('external_orders')->insertOrIgnore([
            'connection_id' => $conn->id,
            'external_order_id' => (string) $wooId,
            'order_id' => $order->id,
            'last_pushed_status' => $wooStatus,
            'last_pushed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $order->id;
    }

    // ------------------------------------------------------------------ product matching

    private function buildItems(IntegrationConnection $conn, array $wooOrder): array
    {
        $items = [];

        foreach ($wooOrder['line_items'] ?? [] as $item) {
            $wooProductId = (int) ($item['product_id'] ?? 0);
            $wooVariationId = (int) ($item['variation_id'] ?? 0);
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $price = (float) ($item['subtotal'] ?? 0) / max(1, $quantity);
            $sku = (string) ($item['sku'] ?? '');
            $name = (string) ($item['name'] ?? 'Unknown product');

            // Try to find a matched local product via external_products.
            $ext = DB::table('external_products')
                ->where('connection_id', $conn->id)
                ->where('external_id', (string) $wooProductId)
                ->where('external_variation_id', $wooVariationId > 0 ? (string) $wooVariationId : '')
                ->where('state', 'matched')
                ->first(['product_id', 'sku']);

            if ($ext && $ext->product_id) {
                // Matched: use our product id.
                $items[] = [
                    'product_id' => $ext->product_id,
                    'name' => $name,
                    'sku' => $sku ?: $ext->sku,
                    'qty' => $quantity,
                    'price' => $price,
                ];
            } else {
                // Unmatched: pass the name so the order line is ad-hoc.
                // An unmatched product NEVER blocks the order.
                $items[] = [
                    'product_id' => null,
                    'name' => $name,
                    'sku' => $sku ?: null,
                    'qty' => $quantity,
                    'price' => $price,
                ];
            }
        }

        return $items;
    }
}
