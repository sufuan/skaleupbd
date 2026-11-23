<?php

// Goes in: app/Http/Controllers/Integrations/ProductMappingController.php (new file).
//
// Backs the product mapping screen on the Connect page.
// Lists unmatched external products, allows the seller to link each one to a local product,
// and supports bulk auto-match by SKU (re-runs the same logic as ImportWooProductsJob).
//
// Business always comes from the authenticated session. All queries are scoped to business_id.
// An unmatched product NEVER blocks an order — this screen is only for the seller's convenience.

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductMappingController extends Controller
{
    public function __construct(private readonly IntegrationHost $host)
    {
    }

    // ------------------------------------------------------------------ GET unmatched

    /**
     * GET /storefront/integrations/products/unmatched
     * Returns all unmatched external products for this shop's active connection(s).
     * Paginated: 50 per page.
     */
    public function unmatched(Request $request): JsonResponse
    {
        $biz  = $this->host->currentBusinessId($request);
        $page = max(1, (int) $request->query('page', 1));

        $connIds = $this->connectionIds($biz);

        if (empty($connIds)) {
            return $this->json(['items' => [], 'total' => 0, 'page' => 1]);
        }

        $query = DB::table('external_products as ep')
            ->join('integration_connections as ic', 'ic.id', '=', 'ep.connection_id')
            ->whereIn('ep.connection_id', $connIds)
            ->where('ep.state', 'unmatched')
            ->orderBy('ep.id');

        $total = $query->count();

        $rows = $query
            ->offset(($page - 1) * 50)
            ->limit(50)
            ->get([
                'ep.id',
                'ep.connection_id',
                'ep.external_id',
                'ep.external_variation_id',
                'ep.sku',
                'ic.platform',
                'ic.site_url',
            ])
            ->map(fn ($r) => [
                'id'            => $r->id,
                'connectionId'  => $r->connection_id,
                'externalId'    => $r->external_id,
                'variationId'   => $r->external_variation_id !== '' ? $r->external_variation_id : null,
                'sku'           => $r->sku,
                'platform'      => $r->platform,
                'siteUrl'       => $r->site_url,
            ]);

        return $this->json(['items' => $rows, 'total' => $total, 'page' => $page]);
    }

    // ------------------------------------------------------------------ GET local products (for the picker)

    /**
     * GET /storefront/integrations/products/local?q=...
     * Search local products by name or SKU for the mapping picker.
     * Returns up to 30 results.
     */
    public function localSearch(Request $request): JsonResponse
    {
        $biz = $this->host->currentBusinessId($request);
        $q   = trim((string) $request->query('q', ''));

        $query = DB::table('products')     // ADAPT: table/column names
            ->where('business_id', $biz)
            ->orderBy('name')               // ADAPT
            ->limit(30);

        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)   // ADAPT
                    ->orWhere('sku', 'like', $like);
            });
        }

        $rows = $query->get(['id', 'name', 'sku'])  // ADAPT
            ->map(fn ($p) => [
                'id'   => $p->id,
                'name' => $p->name,          // ADAPT
                'sku'  => $p->sku,
            ]);

        return $this->json(['products' => $rows]);
    }

    // ------------------------------------------------------------------ POST save a single match

    /**
     * POST /storefront/integrations/products/match
     * Body: { "externalProductId": 123, "localProductId": 456 }
     * Links one external product row to a local product.
     * Passing localProductId = null clears the match (back to unmatched).
     */
    public function match(Request $request): JsonResponse
    {
        $biz = $this->host->currentBusinessId($request);

        $data = $request->validate([
            'externalProductId' => ['required', 'integer'],
            'localProductId'    => ['nullable', 'integer'],
        ]);

        $extId   = (int) $data['externalProductId'];
        $localId = isset($data['localProductId']) ? (int) $data['localProductId'] : null;

        // Scope to this business via connection_id.
        $connIds = $this->connectionIds($biz);

        $row = DB::table('external_products')
            ->whereIn('connection_id', $connIds)
            ->where('id', $extId)
            ->first(['id', 'connection_id']);

        if (! $row) {
            return $this->json(['ok' => false, 'message' => 'Product not found.'], 404);
        }

        if ($localId !== null) {
            // Verify the local product belongs to this business.
            $local = DB::table('products')
                ->where('id', $localId)
                ->where('business_id', $biz)
                ->exists();

            if (! $local) {
                return $this->json(['ok' => false, 'message' => 'Local product not found.'], 404);
            }
        }

        DB::table('external_products')
            ->where('id', $extId)
            ->update([
                'product_id' => $localId,
                'state'      => $localId ? 'matched' : 'unmatched',
                'updated_at' => now(),
            ]);

        $this->host->audit($biz, $request->user()?->getKey(), 'integration.product.matched', [
            'external_product_id' => $extId,
            'local_product_id'    => $localId,
        ]);

        return $this->json(['ok' => true]);
    }

    // ------------------------------------------------------------------ POST bulk auto-match by SKU

    /**
     * POST /storefront/integrations/products/auto-match
     * Re-runs the SKU auto-match for all unmatched external products for this shop.
     * Returns counts of newly matched vs still unmatched.
     */
    public function autoMatch(Request $request): JsonResponse
    {
        $biz     = $this->host->currentBusinessId($request);
        $connIds = $this->connectionIds($biz);

        if (empty($connIds)) {
            return $this->json(['ok' => true, 'matched' => 0, 'stillUnmatched' => 0]);
        }

        $unmatched = DB::table('external_products')
            ->whereIn('connection_id', $connIds)
            ->where('state', 'unmatched')
            ->whereNotNull('sku')
            ->get(['id', 'sku']);

        $nowMatched = 0;

        foreach ($unmatched as $ext) {
            $productId = DB::table('products')
                ->where('business_id', $biz)
                ->where('sku', $ext->sku)
                ->value('id');

            if ($productId) {
                DB::table('external_products')
                    ->where('id', $ext->id)
                    ->update([
                        'product_id' => $productId,
                        'state'      => 'matched',
                        'updated_at' => now(),
                    ]);
                $nowMatched++;
            }
        }

        $stillUnmatched = DB::table('external_products')
            ->whereIn('connection_id', $connIds)
            ->where('state', 'unmatched')
            ->count();

        $this->host->audit($biz, $request->user()?->getKey(), 'integration.product.auto_match', [
            'newly_matched'  => $nowMatched,
            'still_unmatched'=> $stillUnmatched,
        ]);

        return $this->json([
            'ok'            => true,
            'matched'       => $nowMatched,
            'stillUnmatched'=> $stillUnmatched,
        ]);
    }

    // ------------------------------------------------------------------ GET summary counts

    /**
     * GET /storefront/integrations/products/summary
     * Returns matched/unmatched counts for the connection health card.
     */
    public function summary(Request $request): JsonResponse
    {
        $biz     = $this->host->currentBusinessId($request);
        $connIds = $this->connectionIds($biz);

        if (empty($connIds)) {
            return $this->json(['matched' => 0, 'unmatched' => 0]);
        }

        $matched = DB::table('external_products')
            ->whereIn('connection_id', $connIds)
            ->where('state', 'matched')
            ->count();

        $unmatched = DB::table('external_products')
            ->whereIn('connection_id', $connIds)
            ->where('state', 'unmatched')
            ->count();

        return $this->json(['matched' => $matched, 'unmatched' => $unmatched]);
    }

    // ------------------------------------------------------------------ helpers

    private function connectionIds(int $businessId): array
    {
        return DB::table('integration_connections')
            ->where('business_id', $businessId)
            ->whereIn('status', ['active', 'degraded', 'pending'])
            ->pluck('id')
            ->all();
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store');
    }
}
