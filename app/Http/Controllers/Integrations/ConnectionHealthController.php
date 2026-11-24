<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Services\Integrations\IntegrationHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Returns health data for a single integration connection.
 *
 * Place in: app/Http/Controllers/Integrations/ConnectionHealthController.php
 *
 * Routes (add to routes/web.php inside the authenticated integrations group):
 *   GET  /integrations/connections/{connection}/health
 */
final class ConnectionHealthController extends Controller
{
    public function show(Request $request, int $connection, IntegrationHost $host): JsonResponse
    {
        $businessId = $host->currentBusinessId($request);

        $conn = IntegrationConnection::query()
            ->where('id', $connection)
            ->where('business_id', $businessId)
            ->firstOrFail();

        // pointsAvailable() returns the raw balance (int); the label
        // helper below takes the boolean. (Under strict_types, passing
        // the int straight through would be a TypeError.)
        $pointsBalance = $host->pointsAvailable($businessId);
        $hasPoints = $pointsBalance > 0;

        // Pending events (inbound WooCommerce events not yet processed).
        $pendingInbound = DB::table('integration_events')
            ->where('connection_id', $conn->id)
            ->where('status', 'pending')
            ->count();

        // Pending outbound webhook deliveries (custom-site).
        $pendingOutbound = DB::table('webhook_deliveries')
            ->where('connection_id', $conn->id)
            ->whereIn('status', ['pending', 'retrying'])
            ->count();

        // Failed outbound deliveries in last 24 h.
        $recentFailures = DB::table('webhook_deliveries')
            ->where('connection_id', $conn->id)
            ->where('status', 'failed')
            ->where('updated_at', '>=', now()->subDay())
            ->count();

        // Product mapping summary.
        $totalMapped = DB::table('external_products')
            ->where('connection_id', $conn->id)
            ->whereNotNull('product_id')
            ->count();

        $totalUnmatched = DB::table('external_products')
            ->where('connection_id', $conn->id)
            ->whereNull('product_id')
            ->count();

        // Connection-level status string the UI should show in plain words.
        $statusLabel = $this->statusLabel($conn, $hasPoints);

        return response()->json([
            'connection_id'    => $conn->id,
            'type'             => $conn->platform,
            'status'           => $conn->status,
            'status_label'     => $statusLabel,
            'has_points'       => $hasPoints,
            'last_event_at'    => $conn->last_event_at,
            'last_success_at'  => $conn->last_success_at,
            'last_synced_at'   => $conn->last_synced_at,
            'woo_reconciled_at'=> $conn->woo_reconciled_at ?? null,
            'failure_count'    => $conn->failure_count ?? 0,
            'pending_inbound'  => $pendingInbound,
            'pending_outbound' => $pendingOutbound,
            'recent_failures'  => $recentFailures,
            'mapped_products'  => $totalMapped,
            'unmatched_products' => $totalUnmatched,
            'site_url'         => $conn->site_url ?? null,
        ]);
    }

    // -------------------------------------------------------------------------

    private function statusLabel(IntegrationConnection $conn, bool $hasPoints): string
    {
        if (! $hasPoints) {
            return 'paused';          // No points — our side is disconnected.
        }
        // The connection status vocabulary is active / pending / failed /
        // degraded / disabled / paused — there is no 'error' status, so
        // checking for it let failed (and disabled/pending) connections
        // fall through to the card's green "active" rendering.
        if ($conn->status === 'failed' || $conn->status === 'error') {
            return 'error';
        }
        if ($conn->status === 'disabled') {
            return 'disabled';
        }
        if ($conn->status === 'pending') {
            return 'pending';
        }
        if (($conn->failure_count ?? 0) >= 3) {
            return 'degraded';        // Repeated failures but not fully errored.
        }
        if ($conn->status === 'active') {
            return 'active';
        }
        return $conn->status ?? 'unknown';
    }
}
