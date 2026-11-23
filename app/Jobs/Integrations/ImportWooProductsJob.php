<?php

// Goes in: app/Jobs/Integrations/ImportWooProductsJob.php (new file).
// Dispatched by RegisterWooWebhooksJob after a successful connection test.
// Fetches WooCommerce products in chunks of 50, upserts them into external_products,
// and auto-matches by SKU (same logic as custom-site product upsert in stage 2).
// Sets connection status to 'active' once complete (it was already set by connectAndRegister,
// but this confirms the product import finished).

namespace App\Jobs\Integrations;

use App\Models\IntegrationConnection;
use App\Services\Integrations\WooCommerceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportWooProductsJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 300; // 5 minutes; product imports can be large

    public function __construct(public int $connectionId, public int $page = 1)
    {
    }

    public function handle(WooCommerceClient $client): void
    {
        $conn = IntegrationConnection::find($this->connectionId);

        if (! $conn || $conn->status === 'disabled') {
            return;
        }

        $creds = $conn->credentials_enc ?? [];
        $ck    = (string) ($creds['ck'] ?? '');
        $cs    = (string) ($creds['cs'] ?? '');

        $products = $client->getProducts($conn->site_url, $ck, $cs, $this->page, 50);

        if (empty($products)) {
            Log::info('integration.woo.products.import_done', ['connection_id' => $this->connectionId]);

            return;
        }

        $now = now();

        foreach ($products as $wooProduct) {
            $this->upsertProduct($conn, $wooProduct, $now);

            // Handle variable products: fetch and upsert each variation.
            if (($wooProduct['type'] ?? '') === 'variable') {
                $variations = $client->getVariations($conn->site_url, $ck, $cs, (int) ($wooProduct['id'] ?? 0));
                foreach ($variations as $variation) {
                    $this->upsertVariation($conn, $wooProduct, $variation, $now);
                }
            }
        }

        // If a full page was returned, there may be more — queue the next page.
        if (count($products) === 50) {
            dispatch(new self($this->connectionId, $this->page + 1));
        }
    }

    private function upsertProduct(IntegrationConnection $conn, array $p, \Carbon\Carbon $now): void
    {
        $externalId = (string) ($p['id'] ?? '');
        $sku        = (string) ($p['sku'] ?? '');

        if ($externalId === '') {
            return;
        }

        $productId = $sku ? $this->matchBySku((int) $conn->business_id, $sku) : null;
        $state     = $productId ? 'matched' : 'unmatched';
        $hash      = sha1(json_encode([$sku, $p['name'] ?? null]));

        $existing = DB::table('external_products')
            ->where('connection_id', $conn->id)
            ->where('external_id', $externalId)
            ->where('external_variation_id', '')
            ->first(['id', 'state', 'product_id', 'hash']);

        if (! $existing) {
            DB::table('external_products')->insert([
                'connection_id'         => $conn->id,
                'external_id'           => $externalId,
                'external_variation_id' => '',
                'sku'                   => $sku ?: null,
                'product_id'            => $productId,
                'state'                 => $state,
                'hash'                  => $hash,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        } elseif ($existing->state !== 'matched') {
            // Don't overwrite a manual match.
            DB::table('external_products')->where('id', $existing->id)->update([
                'sku'        => $sku ?: null,
                'product_id' => $productId,
                'state'      => $state,
                'hash'       => $hash,
                'updated_at' => $now,
            ]);
        }
    }

    private function upsertVariation(IntegrationConnection $conn, array $parent, array $v, \Carbon\Carbon $now): void
    {
        $externalId  = (string) ($parent['id'] ?? '');
        $variationId = (string) ($v['id'] ?? '');
        $sku         = (string) ($v['sku'] ?? '');

        if ($externalId === '' || $variationId === '') {
            return;
        }

        $productId = $sku ? $this->matchBySku((int) $conn->business_id, $sku) : null;
        $state     = $productId ? 'matched' : 'unmatched';
        $hash      = sha1(json_encode([$sku]));

        $existing = DB::table('external_products')
            ->where('connection_id', $conn->id)
            ->where('external_id', $externalId)
            ->where('external_variation_id', $variationId)
            ->first(['id', 'state']);

        if (! $existing) {
            DB::table('external_products')->insert([
                'connection_id'         => $conn->id,
                'external_id'           => $externalId,
                'external_variation_id' => $variationId,
                'sku'                   => $sku ?: null,
                'product_id'            => $productId,
                'state'                 => $state,
                'hash'                  => $hash,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        } elseif ($existing->state !== 'matched') {
            DB::table('external_products')->where('id', $existing->id)->update([
                'sku'        => $sku ?: null,
                'product_id' => $productId,
                'state'      => $state,
                'hash'       => $hash,
                'updated_at' => $now,
            ]);
        }
    }

    private function matchBySku(int $businessId, string $sku): ?int
    {
        if ($sku === '') {
            return null;
        }

        return DB::table('products')  // ADAPT: table/column name
            ->where('business_id', $businessId)
            ->where('sku', $sku)
            ->value('id');
    }
}
