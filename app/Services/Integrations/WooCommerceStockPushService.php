<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        // Load our product row — column names marked ADAPT if yours differ.
        $product = DB::table('products')
            ->where('id', $productId)
            ->where('business_id', $conn->business_id)
            ->select([
                'id',
                'stock_qty',      // ADAPT: your actual column name
                'reserved_qty',   // ADAPT: your actual column name
            ])
            ->first();

        if (! $product) {
            Log::warning('WooStockPush: product not found', [
                'business_id' => $conn->business_id,
                'product_id'  => $productId,
            ]);
            return false;
        }

        $available = max(0, (int) $product->stock_qty - (int) $product->reserved_qty);

        // Look up the external mapping(s) — a single internal product may map to a
        // WC simple product or one or more WC variations.
        $mappings = DB::table('external_products')
            ->where('connection_id', $conn->id)
            ->where('product_id', $productId)
            ->get(['id', 'external_id', 'external_variation_id', 'last_pushed_stock']);

        if ($mappings->isEmpty()) {
            return false; // Not mapped; nothing to push.
        }

        $pushed = false;

        foreach ($mappings as $map) {
            // Skip if stock has not changed since last push.
            if ((int) $map->last_pushed_stock === $available) {
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
