<?php

// Goes in: app/Services/Integrations/WooCommerceWriteBackService.php (new file).
//
// Pushes a status change and optional tracking note from our system back to WooCommerce.
// Called by PushWooOrderStatusJob (queued — never inline in a web request).
//
// Loop prevention:
//   When we push a status to WooCommerce, it fires an order.updated webhook back to us.
//   We prevent an import loop by tracking external_orders.last_pushed_status:
//   - After a successful push: set last_pushed_status = the WooCommerce status we just wrote.
//   - In ProcessWooWebhookJob (stage 3): when order.updated arrives, compare the incoming
//     WooCommerce status to last_pushed_status. If they match, it is our echo — skip import.
//
// Status map (our status → WooCommerce status string):
//   Processing → null (not pushed back; we received "processing" FROM WC, don't echo it)
//   Shipped    → "processing" + tracking note   (verify: "shipped" status needs a plugin on most WC sites)
//   Delivered  → "completed"
//   Returned   → "refunded"                      (verify: or "cancelled" depending on seller preference)
//   Cancelled  → "cancelled"
//
// These defaults are stored in config/integrations.php and can be overridden per connection
// (woo_status_map already stores the inbound map; a separate woo_push_map is added in stage 7 UI).
// For now we use the hard-coded defaults below.

namespace App\Services\Integrations;

use App\Models\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WooCommerceWriteBackService
{
    // Our status → WooCommerce status (null = do not push).
    // (verify) "refunded" requires a manual refund to exist in WooCommerce; "cancelled" is safer for Returned.
    public const PUSH_STATUS_MAP = [
        'Processing' => null,           // we received it from WC; do not echo back
        'Shipped'    => 'processing',   // (verify) most WC installs have no "shipped" status
        'Delivered'  => 'completed',
        'Returned'   => 'cancelled',    // (verify) adjust per seller preference
        'Cancelled'  => 'cancelled',
    ];

    public function __construct(private readonly WooCommerceClient $client)
    {
    }

    /**
     * Push our order's new status (and tracking info) back to the WooCommerce order.
     * Returns true on success, false on failure.
     *
     * Called only from PushWooOrderStatusJob.
     */
    public function push(IntegrationConnection $conn, int $orderId, string $ourStatus): bool
    {
        // Resolve the WooCommerce external order id.
        $ext = DB::table('external_orders')
            ->where('connection_id', $conn->id)
            ->where('order_id', $orderId)
            ->first(['id', 'external_order_id', 'last_pushed_status']);

        if (! $ext) {
            Log::info('integration.woo.writeback.no_ext_order', [
                'connection_id' => $conn->id,
                'order_id'      => $orderId,
            ]);

            return false;
        }

        // --- Loop prevention: if we already pushed this exact status, skip.
        $wooStatus = self::PUSH_STATUS_MAP[$ourStatus] ?? null;

        if ($wooStatus === null) {
            // Not mapped — nothing to push (e.g. Processing is not echoed back).
            return true;
        }

        if ($ext->last_pushed_status === $wooStatus) {
            // We already pushed this WooCommerce status. This call is likely triggered by
            // a reconciliation re-run or duplicate observer fire. Skip.
            Log::info('integration.woo.writeback.already_pushed', [
                'connection_id' => $conn->id,
                'order_id'      => $orderId,
                'woo_status'    => $wooStatus,
            ]);

            return true;
        }

        $wooOrderId = (int) $ext->external_order_id;
        $creds      = $conn->credentials_enc ?? [];
        $ck         = (string) ($creds['ck'] ?? '');
        $cs         = (string) ($creds['cs'] ?? '');

        // --- Build the update payload.
        $payload = ['status' => $wooStatus];

        // For Shipped: add a tracking note as a WooCommerce order note.
        if ($ourStatus === 'Shipped') {
            $trackingNote = $this->buildTrackingNote($orderId);
            if ($trackingNote) {
                $payload['customer_note'] = true; // make it a customer-facing note
                // We add the note in a separate API call below (WC REST separates notes from status).
            }
        }

        // --- Push status update.
        $url = rtrim($conn->site_url, '/') . '/wp-json/wc/v3/orders/' . $wooOrderId;

        try {
            $response = $this->client->putJson($url, $ck, $cs, json_encode($payload));

            if (! $response->successful()) {
                Log::warning('integration.woo.writeback.failed', [
                    'connection_id' => $conn->id,
                    'order_id'      => $orderId,
                    'woo_order_id'  => $wooOrderId,
                    'http_status'   => $response->status(),
                ]);

                return false;
            }
        } catch (\Throwable $e) {
            Log::error('integration.woo.writeback.exception', [
                'connection_id' => $conn->id,
                'order_id'      => $orderId,
                'error'         => $e->getMessage(),
            ]);

            return false;
        }

        // --- Push tracking note (separate WC order notes endpoint).
        if ($ourStatus === 'Shipped') {
            $noteText = $this->buildTrackingNote($orderId);
            if ($noteText) {
                $this->pushNote($conn, $wooOrderId, $noteText, $ck, $cs);
            }
        }

        // --- Record the pushed status for loop prevention.
        DB::table('external_orders')
            ->where('id', $ext->id)
            ->update([
                'last_pushed_status' => $wooStatus,
                'last_pushed_at'     => now(),
                'updated_at'         => now(),
            ]);

        // Update connection health.
        $conn->update(['last_success_at' => now(), 'failure_count' => 0, 'last_error' => null]);

        return true;
    }

    // ------------------------------------------------------------------ helper: tracking note

    /**
     * Build a plain-text tracking note from the order's courier + tracking number.
     * Returns null if no tracking info is available yet.
     */
    private function buildTrackingNote(int $orderId): ?string
    {
        $order = DB::table('orders')
            ->where('id', $orderId)
            ->first(['delivery_method', 'tracking_id', 'consignment_id']);

        if (! $order) {
            return null;
        }

        $courier  = trim((string) ($order->delivery_method ?? ''));
        $tracking = trim((string) ($order->tracking_id ?? $order->consignment_id ?? ''));

        if ($tracking === '') {
            return null;
        }

        return $courier
            ? "Shipped via {$courier}. Tracking: {$tracking}"
            : "Tracking number: {$tracking}";
    }

    // ------------------------------------------------------------------ helper: order note

    private function pushNote(IntegrationConnection $conn, int $wooOrderId, string $note, string $ck, string $cs): void
    {
        $url = rtrim($conn->site_url, '/') . '/wp-json/wc/v3/orders/' . $wooOrderId . '/notes';

        try {
            $this->client->postJson($url, $ck, $cs, json_encode([
                'note'           => $note,
                'customer_note'  => true,
            ]));
        } catch (\Throwable $e) {
            // Note failure is not critical — the status was already pushed.
            Log::warning('integration.woo.writeback.note_failed', [
                'connection_id' => $conn->id,
                'woo_order_id'  => $wooOrderId,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------ loop guard (called by ProcessWooWebhookJob)

    /**
     * Returns true when the incoming WooCommerce order.updated event is an echo of our own push.
     * Call this in ProcessWooWebhookJob before importing an updated order.
     */
    public static function isEcho(int $connectionId, int $wooOrderId, string $incomingWooStatus): bool
    {
        $ext = DB::table('external_orders')
            ->where('connection_id', $connectionId)
            ->where('external_order_id', (string) $wooOrderId)
            ->first(['last_pushed_status', 'last_pushed_at']);

        if (! $ext || $ext->last_pushed_status === null) {
            return false;
        }

        // If the incoming WooCommerce status matches the status we last pushed, it is an echo.
        // We also require that the push happened recently (within 10 minutes) to avoid
        // suppressing a genuine status change that happens to have the same status string.
        $pushedAt = $ext->last_pushed_at ? \Carbon\Carbon::parse($ext->last_pushed_at) : null;
        $isRecent = $pushedAt && $pushedAt->diffInMinutes(now()) < 10;

        return $isRecent && $ext->last_pushed_status === $incomingWooStatus;
    }
}
