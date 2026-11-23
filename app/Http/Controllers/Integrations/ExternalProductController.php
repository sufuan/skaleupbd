<?php

// Goes in: app/Http/Controllers/Integrations/ExternalProductController.php (new file).
// Handles:
//   POST /api/external/v1/products      — bulk upsert by external_id (max 100 per call)
//   POST /api/external/v1/stock/check   — check available stock for a list of external_ids
//
// Business is ALWAYS from $request->attributes->get('api_key')->business_id.
// Zero-points rule: when suspended, product writes and stock reads return 503 with a clear message.
// The seller's own site is never touched by these endpoints.

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\IntegrationConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ExternalProductController extends Controller
{
    // ------------------------------------------------------------------ POST /api/external/v1/products

    /**
     * Bulk upsert products by external_id (up to 100 per call).
     *
     * Request body:
     * {
     *   "products": [
     *     {
     *       "externalId": "PROD-123",          // required, your site's product id
     *       "variationId": "",                  // optional, variation id or ""
     *       "sku": "SKU-ABC",                  // optional but recommended for auto-matching
     *       "name": "Blue T-Shirt",            // optional, used for display in unmatched list
     *       "price": 500                        // optional
     *     }
     *   ]
     * }
     *
     * Response:
     * {
     *   "success": true,
     *   "statusCode": 200,
     *   "data": {
     *     "received": 3,
     *     "matched": 2,       // already matched to a local product
     *     "unmatched": 1      // in the mapping queue; seller must match these in the dashboard
     *   }
     * }
     */
    public function upsert(Request $request): JsonResponse
    {
        $key = $this->key($request);

        if (! $key->grants('products.write')) {
            return $this->deny('This key does not have permission to write products.');
        }

        $this->assertNotSuspended($key->business_id);

        $validated = $request->validate([
            'products'                  => ['required', 'array', 'min:1', 'max:100'],
            'products.*.externalId'     => ['required', 'string', 'max:64'],
            'products.*.variationId'    => ['sometimes', 'nullable', 'string', 'max:64'],
            'products.*.sku'            => ['sometimes', 'nullable', 'string', 'max:191'],
            'products.*.name'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'products.*.price'          => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $conn = $this->connection($key->business_id);
        if (! $conn) {
            return $this->deny('No active integration connection found for this key. Create a connection in the dashboard first.', 422);
        }

        $matched = 0;
        $unmatched = 0;
        $now = now();

        foreach ($validated['products'] as $item) {
            $externalId  = (string) $item['externalId'];
            $variationId = (string) ($item['variationId'] ?? '');
            $sku         = isset($item['sku']) ? (string) $item['sku'] : null;

            // Try to auto-match by SKU if not already matched.
            $productId = null;
            $state     = 'unmatched';

            if ($sku) {
                $productId = DB::table('products')
                    ->where('business_id', $key->business_id)
                    ->where('sku', $sku)
                    ->value('id');

                if ($productId) {
                    $state = 'matched';
                }
            }

            // Build a lightweight hash to detect changes (so we don't hammer the DB on every sync).
            $hash = sha1(json_encode([$sku, $item['name'] ?? null, $item['price'] ?? null]));

            // Upsert into external_products.
            $existing = DB::table('external_products')
                ->where('connection_id', $conn->id)
                ->where('external_id', $externalId)
                ->where('external_variation_id', $variationId)
                ->first(['id', 'state', 'product_id', 'hash']);

            if (! $existing) {
                DB::table('external_products')->insert([
                    'connection_id'       => $conn->id,
                    'external_id'         => $externalId,
                    'external_variation_id' => $variationId,
                    'sku'                 => $sku,
                    'product_id'          => $productId,
                    'state'               => $state,
                    'hash'                => $hash,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            } else {
                // Respect a manual match the seller made in the dashboard; only override if unmatched.
                $newState     = ($existing->state === 'matched') ? 'matched' : $state;
                $newProductId = ($existing->state === 'matched') ? $existing->product_id : $productId;

                if ($existing->hash !== $hash || $existing->state !== $newState) {
                    DB::table('external_products')
                        ->where('id', $existing->id)
                        ->update([
                            'sku'        => $sku,
                            'product_id' => $newProductId,
                            'state'      => $newState,
                            'hash'       => $hash,
                            'updated_at' => $now,
                        ]);
                }

                $state = $newState;
            }

            if ($state === 'matched') {
                $matched++;
            } else {
                $unmatched++;
            }
        }

        return $this->envelope(true, 200, $request->header('x-request-id', Str::uuid()->toString()), [
            'received'  => count($validated['products']),
            'matched'   => $matched,
            'unmatched' => $unmatched,
        ]);
    }

    // ------------------------------------------------------------------ POST /api/external/v1/stock/check

    /**
     * Check available stock for a list of your external product ids.
     *
     * Request body:
     * {
     *   "items": [
     *     { "externalId": "PROD-123", "variationId": "" }
     *   ]
     * }
     *
     * Response:
     * {
     *   "success": true,
     *   "statusCode": 200,
     *   "data": {
     *     "items": [
     *       {
     *         "externalId": "PROD-123",
     *         "variationId": "",
     *         "available": 42,         // null when product is not matched
     *         "matched": true
     *       }
     *     ]
     *   }
     * }
     */
    public function stockCheck(Request $request): JsonResponse
    {
        $key = $this->key($request);

        if (! $key->grants('stock.read')) {
            return $this->deny('This key does not have permission to read stock.');
        }

        $this->assertNotSuspended($key->business_id);

        $validated = $request->validate([
            'items'                 => ['required', 'array', 'min:1', 'max:100'],
            'items.*.externalId'    => ['required', 'string', 'max:64'],
            'items.*.variationId'   => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $conn = $this->connection($key->business_id);
        if (! $conn) {
            return $this->envelope(true, 200, $request->header('x-request-id', Str::uuid()->toString()), [
                'items' => array_map(fn ($i) => [
                    'externalId' => $i['externalId'],
                    'variationId' => $i['variationId'] ?? '',
                    'available' => null,
                    'matched' => false,
                ], $validated['items']),
            ]);
        }

        // Fetch all matched external_products for this connection in one query.
        $externalIds = array_map(fn ($i) => $i['externalId'], $validated['items']);

        $rows = DB::table('external_products as ep')
            ->join('products as p', 'p.id', '=', 'ep.product_id')
            ->where('ep.connection_id', $conn->id)
            ->where('ep.state', 'matched')
            ->whereIn('ep.external_id', $externalIds)
            ->get(['ep.external_id', 'ep.external_variation_id', 'p.stock_qty', 'p.reserved_qty'])
            ->keyBy(fn ($r) => $r->external_id . '|' . $r->external_variation_id);

        $result = [];
        foreach ($validated['items'] as $item) {
            $eid = $item['externalId'];
            $vid = (string) ($item['variationId'] ?? '');
            $row = $rows->get($eid . '|' . $vid);

            $result[] = [
                'externalId'  => $eid,
                'variationId' => $vid,
                'available'   => $row ? max(0, (int) $row->stock_qty - (int) $row->reserved_qty) : null,
                'matched'     => (bool) $row,
            ];
        }

        return $this->envelope(true, 200, $request->header('x-request-id', Str::uuid()->toString()), [
            'items' => $result,
        ]);
    }

    // ------------------------------------------------------------------ helpers

    private function key(Request $request): ApiKey
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');
        abort_if(! $key, 401);

        return $key;
    }

    private function connection(int $businessId): ?object
    {
        // Returns the first active custom connection for this business.
        return DB::table('integration_connections')
            ->where('business_id', $businessId)
            ->where('platform', 'custom')
            ->whereIn('status', ['active', 'degraded'])
            ->first(['id']);
    }

    private function assertNotSuspended(int $businessId): void
    {
        // IntegrationHost is wired to the real wallet (TokenService), so
        // this check is fail-closed: no LogicException escape hatch.
        $host = app(\App\Services\Integrations\IntegrationHost::class);
        if (! $host->hasPoints($businessId)) {
            abort(response()->json([
                'success'    => false,
                'statusCode' => 503,
                'requestId'  => null,
                'message'    => 'This connection is suspended because the shop has run out of points. The order on your side is unaffected. Please try again after the shop tops up.',
            ], 503));
        }
    }

    private function envelope(bool $success, int $code, ?string $requestId, array $data): JsonResponse
    {
        return response()->json([
            'success'    => $success,
            'statusCode' => $code,
            'requestId'  => $requestId,
            'data'       => $data,
        ], $code)->header('Cache-Control', 'no-store');
    }

    private function deny(string $message, int $code = 403): JsonResponse
    {
        return response()->json([
            'success'    => false,
            'statusCode' => $code,
            'requestId'  => null,
            'message'    => $message,
        ], $code)->header('Cache-Control', 'no-store');
    }
}
