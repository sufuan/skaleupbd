<?php

// Goes in: app/Http/Controllers/Integrations/WooStatusMapController.php (new file).
//
// Backs WooStatusMapSection.jsx.
// GET  /storefront/integrations/woo/status-map  → returns the current push map
// PUT  /storefront/integrations/woo/status-map  → saves a new push map
//
// The push map is stored in integration_connections.woo_status_map as JSON.
// (Note: woo_status_map currently stores the INBOUND map (WC status → our status).
//  Stage 7 will split this into two columns. For now we store the push map in a
//  new column woo_push_map added by migration 000044.)

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Services\Integrations\IntegrationHost;
use App\Services\Integrations\WooCommerceWriteBackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WooStatusMapController extends Controller
{
    // Valid WooCommerce status strings we allow in the push map.
    private const VALID_WC_STATUSES = [
        'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed',
    ];

    public function __construct(private readonly IntegrationHost $host)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $biz  = $this->host->currentBusinessId($request);
        $conn = $this->connection($biz);

        $pushMap = $conn
            ? (json_decode((string) ($conn->woo_push_map ?? ''), true) ?? WooCommerceWriteBackService::PUSH_STATUS_MAP)
            : WooCommerceWriteBackService::PUSH_STATUS_MAP;

        return $this->json(['pushMap' => $pushMap]);
    }

    public function update(Request $request): JsonResponse
    {
        $biz = $this->host->currentBusinessId($request);

        $data = $request->validate([
            'pushMap'   => ['required', 'array'],
            'pushMap.*' => ['nullable', 'string', 'in:' . implode(',', array_merge([''], self::VALID_WC_STATUSES))],
        ]);

        // Strip null/"" values to keep storage clean; ensure only known our-statuses are set.
        $allowed = array_keys(WooCommerceWriteBackService::PUSH_STATUS_MAP);
        $cleaned = [];
        foreach ($data['pushMap'] as $ourStatus => $wooStatus) {
            if (in_array($ourStatus, $allowed, true)) {
                $cleaned[$ourStatus] = ($wooStatus === '' || $wooStatus === null) ? null : $wooStatus;
            }
        }

        $conn = $this->connection($biz);
        if (! $conn) {
            return $this->json(['ok' => false, 'message' => 'No WooCommerce connection found.'], 422);
        }

        $conn->update(['woo_push_map' => json_encode($cleaned)]);

        $this->host->audit($biz, $request->user()?->getKey(), 'integration.woo.push_map_updated', [
            'connection_id' => $conn->id,
        ]);

        return $this->json(['ok' => true]);
    }

    private function connection(int $businessId): ?IntegrationConnection
    {
        return IntegrationConnection::query()
            ->forBusiness($businessId)
            ->where('platform', 'woo')
            ->first();
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store');
    }
}
