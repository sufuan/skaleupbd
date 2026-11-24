<?php

// Goes in: app/Services/Integrations/OrderEventPayloadBuilder.php (new file).
//
// Every assumed column name of the existing orders and products tables is in THIS file only.
// Each assumption is marked ADAPT. Check them against the real schema before first use.

namespace App\Services\Integrations;

use Illuminate\Support\Facades\DB;

class OrderEventPayloadBuilder
{
    /** @return array{courier:?string,trackingNumber:?string} */
    public function shipment(object $order): array
    {
        // Real columns: orders.delivery_method holds the courier name;
        // the tracking id lives in tracking_id, falling back to
        // consignment_id (both written by the delivery module).
        return [
            'courier' => $this->clean(data_get($order, 'delivery_method')),
            'trackingNumber' => $this->clean(data_get($order, 'tracking_id') ?? data_get($order, 'consignment_id')),
        ];
    }

    public function orderData(object $order): array
    {
        $ship = $this->shipment($order);

        return [
            'orderId' => $order->getKey(),                                   // ADAPT: the id the inbound API returns as orderId
            'invoice' => $this->clean(data_get($order, 'invoice_no')),         // ADAPT: orders.invoice_no
            'externalRef' => $this->clean(data_get($order, 'external_ref')),   // ADAPT: orders.external_ref
            'status' => (string) $order->status,
            'courier' => $ship['courier'],
            'trackingNumber' => $ship['trackingNumber'],
        ];
    }

    /** Live stock truth for one product, or null when the product is not matched to the seller's site. */
    public function stockData(int $businessId, int $connectionId, int $productId): ?array
    {
        $product = \App\Models\Product::where('business_id', $businessId)->find($productId);

        $ext = DB::table('external_products')
            ->where('connection_id', $connectionId)
            ->where('product_id', $productId)
            ->where('state', 'matched')
            ->orderBy('external_variation_id')
            ->first(['external_id', 'external_variation_id', 'variant_id']);

        if (! $product || ! $ext) {
            return null;
        }

        // The shop's own availability definition: a matched variant row
        // reports that variant's pool; otherwise Product::available()
        // (for a combo, how many full combos its components can make).
        $available = max(0, $product->available());
        if ($ext->variant_id) {
            $variant = \App\Models\ProductVariant::find($ext->variant_id);
            if ($variant) {
                $available = $variant->available();
            }
        }

        return [
            'externalId' => $ext->external_id,
            'externalVariationId' => $ext->external_variation_id !== '' ? $ext->external_variation_id : null,
            'sku' => $product->sku,
            'available' => $available,
        ];
    }

    private function clean(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
