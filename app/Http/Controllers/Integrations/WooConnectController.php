<?php

// Goes in: app/Http/Controllers/Integrations/WooConnectController.php (new file).
// Backs the WooCommerce section of the Connect page.
// All calls to the seller's WooCommerce site are queued — never inline.
//
// Business is always from the authenticated seller session (IntegrationHost::currentBusinessId).
// Credentials are stored encrypted in integration_connections.credentials_enc and NEVER returned
// to the browser. The consumer key is not shown after save (not even last-4 — it is not a secret
// you need to reference later; the seller has it in WooCommerce admin).

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Services\Integrations\IntegrationHost;
use App\Services\Integrations\WooCommerceConnectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WooConnectController extends Controller
{
    public function __construct(
        private readonly IntegrationHost           $host,
        private readonly WooCommerceConnectService $woo,
    ) {
    }

    // ------------------------------------------------------------------ GET state

    /**
     * GET /storefront/integrations/woo
     * Returns the current WooCommerce connection state for the Connect page.
     */
    public function show(Request $request): JsonResponse
    {
        $biz  = $this->host->currentBusinessId($request);

        return $this->json($this->state($biz));
    }

    // ------------------------------------------------------------------ POST connect (save + queue test)

    /**
     * POST /storefront/integrations/woo
     * Body: { "site_url": "https://...", "consumer_key": "ck_...", "consumer_secret": "cs_..." }
     * Saves credentials (encrypted) and queues the connection test + webhook registration job.
     * Returns immediately with status = "pending".
     */
    public function store(Request $request): JsonResponse
    {
        $biz = $this->host->currentBusinessId($request);

        $data = $request->validate([
            'site_url'        => ['required', 'string', 'max:500'],
            'consumer_key'    => ['required', 'string', 'min:10', 'max:120'],
            'consumer_secret' => ['required', 'string', 'min:10', 'max:120'],
        ]);

        try {
            $conn = $this->woo->saveAndQueue(
                $biz,
                $data['site_url'],
                $data['consumer_key'],
                $data['consumer_secret'],
            );
        } catch (\App\Services\Integrations\UnsafeUrlException $e) {
            return $this->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $this->host->audit($biz, $request->user()?->getKey(), 'integration.woo.connect_queued', [
            'connection_id' => $conn->id,
            'site_url'      => $data['site_url'],
        ]);

        return $this->json(['ok' => true, 'connection' => $this->state($biz)]);
    }

    // ------------------------------------------------------------------ DELETE disconnect

    /**
     * DELETE /storefront/integrations/woo
     * Removes all WooCommerce webhooks from the seller's site and marks the connection disabled.
     */
    public function destroy(Request $request): JsonResponse
    {
        $biz  = $this->host->currentBusinessId($request);
        $conn = $this->connection($biz);

        if ($conn) {
            $this->woo->disconnect($conn);
            $this->host->audit($biz, $request->user()?->getKey(), 'integration.woo.disconnected', [
                'connection_id' => $conn->id,
            ]);
        }

        return $this->json(['ok' => true, 'connection' => $this->state($biz)]);
    }

    // ------------------------------------------------------------------ helper: event inbox (inbound events)

    /**
     * GET /storefront/integrations/woo/events
     * Returns the most recent 30 inbound events for the WooCommerce connection.
     */
    public function events(Request $request): JsonResponse
    {
        $biz  = $this->host->currentBusinessId($request);
        $conn = $this->connection($biz);

        if (! $conn) {
            return $this->json(['events' => []]);
        }

        $events = DB::table('integration_events')
            ->where('connection_id', $conn->id)
            ->where('direction', 'in')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'topic', 'status', 'subject_id', 'attempts', 'error', 'created_at'])
            ->map(fn ($e) => [
                'id'        => $e->id,
                'topic'     => $e->topic,
                'status'    => $e->status,
                'orderId'   => $e->subject_id,
                'attempts'  => $e->attempts,
                'error'     => $e->error,
                'at'        => $e->created_at,
            ]);

        return $this->json(['events' => $events]);
    }

    // ------------------------------------------------------------------ state builder

    private function state(int $businessId): array
    {
        $conn = $this->connection($businessId);

        if (! $conn) {
            return ['configured' => false];
        }

        $paused = $conn->status !== 'disabled' && ! $this->host->hasPoints($businessId);

        // Count unprocessed inbound events.
        $pendingIn = DB::table('integration_events')
            ->where('connection_id', $conn->id)
            ->where('direction', 'in')
            ->whereIn('status', ['received', 'failed'])
            ->count();

        return [
            'configured'    => true,
            'status'        => $paused ? 'paused' : $conn->status,
            'site_url'      => $conn->site_url,
            'last_event_at' => $conn->last_event_at?->toIso8601String(),
            'last_success_at'=> $conn->last_success_at?->toIso8601String(),
            'failure_count' => $conn->failure_count,
            'last_error'    => $conn->last_error,
            'pending_in'    => $pendingIn,
            'top_up_url'    => (string) config('integrations.top_up_url'),
        ];
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
