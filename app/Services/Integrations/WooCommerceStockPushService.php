<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Pushes stock quantities from our system back to a WooCommerce store.
 *
 * Place in: app/Services/Integrations/WooCommerceStockPushService.php
 *
 * Rules:
 *  - Zero-points: skip entirely (IntegrationHost::pointsAvailable check is done in the job).
 *  - Only pushes when our `available` qty differs from the last-pushed qty stored in
 *    external_products.last_pushed_stock (added by migration 000045).
 *  - Handles both simple products (external_id) and variations (external_variation_id).
 *  - On failure logs and throws so the job can retry.
 */
final class WooCommerceStockPushService
{
    public function __construct(
        private readonly WooCommerceClient $client,
    ) {}

    /**
     * Push stock for a single product to WooCommerce.
     *
     * @param  IntegrationConnection  $conn
     * @param  int  $productId  — our internal products.id
     * @return bool  true if pushed, false if skipped (no change or no mapping)
     */
    public function pushProduct(IntegrationConnection $conn, int $productId): bool
    {
        $product = Product::where('business_id', $conn->business_id)->find($productId);

        if (! $product) {
            Log::warning('WooStockPush: product not found', [
                'business_id' => $conn->business_id,
                'product_id'  => $productId,
            ]);
            return false;
        }

        // Look up the external mapping(s) — a single internal product may map to a
        // WC simple product or one or more WC variations.
        $columns = ['id', 'external_id', 'external_variation_id', 'last_pushed_stock', 'sku', 'product_id'];
        $hasVariantColumn = Schema::hasColumn('external_products', 'variant_id');
        if ($hasVariantColumn) {
            $columns[] = 'variant_id';
        }
        $mappings = DB::table('external_products')
            ->where('connection_id', $conn->id)
            ->where('product_id', $productId)
            ->get($columns);

        if ($mappings->isEmpty()) {
            return false; // Not mapped; nothing to push.
        }

        $pushed = false;

        foreach ($mappings as $map) {
            // R2: every mapping gets the figure of the entity it actually
            // points at - the variant pool for a variation mapping,
            // Product::available() otherwise (a combo's makeable count
            // included). The old code pushed the parent row's
            // stock-minus-reserved to every mapping, which marked
            // variations and combos out of stock on the seller's real
            // store while they held stock.
            $variant = $this->resolveVariant($product, $map, $hasVariantColumn);
            $available = $variant
                ? max(0, (int) $variant->stock_qty - (int) $variant->reserved_qty)
                : $product->available();

            // Skip only when a previous push actually recorded this same
            // figure. last_pushed_stock NULL means "never pushed" - it
            // must not read as 0 and swallow a first push of 0.
            if ($map->last_pushed_stock !== null && (int) $map->last_pushed_stock === $available) {
                continue;
            }

            $this->doPush($conn, $map, $available);

            DB::table('external_products')
                ->where('id', $map->id)
                ->update(['last_pushed_stock' => $available, 'updated_at' => now()]);

            $pushed = true;
        }

        return $pushed;
    }

    /**
     * The local variant a variation mapping points at: the remembered
     * variant_id when the mapping carries one, else the product's
     * variant with the mapping's SKU. A resolved variant is remembered
     * back onto the mapping row, so imports and later pushes resolve
     * the same way (R2/R4).
     */
    private function resolveVariant(Product $product, object $map, bool $hasVariantColumn): ?ProductVariant
    {
        if (empty($map->external_variation_id)) {
            return null;
        }
        $variant = null;
        if ($hasVariantColumn && ! empty($map->variant_id)) {
            $variant = $product->variants()->find((int) $map->variant_id);
        }
        if (! $variant && ! empty($map->sku)) {
            $variant = $product->variants()->where('sku', $map->sku)->first();
        }
        if ($variant && $hasVariantColumn && (int) ($map->variant_id ?? 0) !== (int) $variant->id) {
            DB::table('external_products')->where('id', $map->id)
                ->update(['variant_id' => $variant->id, 'updated_at' => now()]);
        }

        return $variant;
    }

    /**
     * Push all mapped products for a connection (used by reconciliation).
     *
     * @return int  number of products actually pushed
     */
    public function pushAll(IntegrationConnection $conn): int
    {
        $productIds = DB::table('external_products')
            ->where('connection_id', $conn->id)
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->unique();

        $count = 0;
        foreach ($productIds as $pid) {
            try {
                if ($this->pushProduct($conn, (int) $pid)) {
                    $count++;
                }
            } catch (\Throwable $e) {
                Log::error('WooStockPush: pushAll error', [
                    'connection_id' => $conn->id,
                    'product_id'    => $pid,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    // -------------------------------------------------------------------------

    private function doPush(IntegrationConnection $conn, object $map, int $qty): void
    {
        $wooId       = (int) $map->external_id;
        $variationId = $map->external_variation_id ? (int) $map->external_variation_id : null;

        $payload = [
            'manage_stock'   => true,
            'stock_quantity' => $qty,
            'stock_status'   => $qty > 0 ? 'instock' : 'outofstock',
        ];

        $creds = $conn->credentials_enc ?? [];
        $ck    = (string) ($creds['ck'] ?? '');
        $cs    = (string) ($creds['cs'] ?? '');

        // PUT /wp-json/wc/v3/products/{id} or .../products/{id}/variations/{variation_id}
        $path = $variationId
            ? "products/{$wooId}/variations/{$variationId}"
            : "products/{$wooId}";
        $url = rtrim((string) $conn->site_url, '/') . '/wp-json/wc/v3/' . $path;

        $response = $this->client->putJson($url, $ck, $cs, json_encode($payload));

        if (! $response->successful()) {
            // Throw so pushProduct does NOT record last_pushed_stock for a
            // push that never landed; the job can retry.
            throw new \RuntimeException('WooCommerce stock push failed: HTTP ' . $response->status());
        }

        Log::info('WooStockPush: pushed', [
            'connection_id' => $conn->id,
            'product_id'    => $map->product_id ?? null,
            'woo_id'        => $wooId,
            'variation_id'  => $variationId,
            'qty'           => $qty,
        ]);
    }
}
