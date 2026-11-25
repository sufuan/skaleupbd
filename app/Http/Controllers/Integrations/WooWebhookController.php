<?php

// Goes in: app/Http/Controllers/Integrations/WooWebhookController.php (new file).
//
// Receives inbound WooCommerce webhooks at:
//   POST /api/webhooks/woo/{token}/{type}/{event}
// e.g. /api/webhooks/woo/abc123.../order/created
//
// Security model:
//   1. {token} is an unguessable 48-char random string stored in integration_connections.public_token.
//      It is NOT a secret — it just prevents enumeration of this endpoint.
//   2. The REAL guard is HMAC-SHA256 verification using our webhook_secret_enc.
//      WooCommerce sends the signature in X-WC-Webhook-Signature (verify: exact header name in WC docs).
//   3. We answer 200 IMMEDIATELY after storing the raw payload. All processing is queued.
//      This prevents WooCommerce from auto-disabling the webhook after 5 delivery failures.
//   4. Zero-points rule: the event is STORED even at zero points; a job checks balance when it runs.
//   5. Deduplication: dedupe_key = sha1(connection + topic + sha256(raw body)).
//      The key is built from the FULL payload, never from the
//      X-WC-Webhook-ID header: that header carries the webhook's CONFIG
//      id (one small integer per topic), not a per-delivery id, and
//      event_uuid is UNIQUE - keying dedupe on it silently discarded
//      every delivery after the first per topic (Phase 3, Defect 1).
//      An identical re-delivery hashes to the same key and is dropped
//      as a true duplicate; any changed payload (a new order, a status
//      update) hashes differently and is processed.
//
// CSRF: routes/web.php puts this under api/webhooks/* which is already CSRF-exempt (bootstrap/app.php).

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Jobs\Integrations\ProcessWooWebhookJob;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEvent;
use App\Services\Integrations\WooCommerceHmac;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WooWebhookController extends Controller
{
    /**
     * @param  string  $token   public_token from integration_connections
     * @param  string  $type    'order' or 'product'
     * @param  string  $event   'created' or 'updated'
     */
    public function receive(Request $request, string $token, string $type, string $event): Response
    {
        // --- Always answer 200 fast (see WooCommerce auto-disable behaviour)
        $rawBody = $request->getContent();

        // --- Find connection by public_token (not secret)
        $conn = IntegrationConnection::query()
            ->where('public_token', $token)
            ->where('platform', 'woo')
            ->first();

        if (! $conn) {
            // 200 so WooCommerce does not disable the webhook; we just ignore it.
            return response('', 200);
        }

        // --- Verify HMAC signature
        // WooCommerce signs with HMAC-SHA256 of the raw body using the webhook secret.
        // Header: X-WC-Webhook-Signature (base64-encoded). (verify: confirm header name in WC docs)
        $sigHeader = (string) $request->header('X-WC-Webhook-Signature', '');
        $secret    = (string) $conn->webhook_secret_enc;

        if (! WooCommerceHmac::verify($secret, $sigHeader, $rawBody)) {
            Log::warning('integration.woo.hmac_fail', [
                'connection_id' => $conn->id,
                'topic'         => $type . '.' . $event,
            ]);
            // Still return 200 — returning 4xx would cause WooCommerce to auto-disable. We just discard.
            return response('', 200);
        }

        // --- Build topic + the per-delivery dedupe key (see header note 5:
        // X-WC-Webhook-ID is the webhook's config id, NOT a delivery id,
        // so it plays no part in dedupe or in the unique event_uuid).
        $topic     = $type . '.' . $event; // e.g. "order.created"
        $dedupeKey = 'woo:in:' . sha1($conn->id . '|' . $topic . '|' . hash('sha256', $rawBody));

        // --- Store the event (even at zero points)
        try {
            $eventRow = IntegrationEvent::create([
                'connection_id'  => $conn->id,
                'direction'      => 'in',
                'topic'          => $topic,
                'event_uuid'     => (string) Str::uuid(),
                'subject_id'     => null, // filled by the processing job
                'dedupe_key'     => $dedupeKey,
                'payload'        => $rawBody,
                'status'         => 'received',
                'attempts'       => 0,
                'next_attempt_at'=> now(),
            ]);

            // Update connection heartbeat
            $conn->update(['last_event_at' => now()]);

            // Queue the processing job
            dispatch(new ProcessWooWebhookJob($eventRow->id));
        } catch (UniqueConstraintViolationException) {
            // Duplicate delivery — WooCommerce sometimes retries. Already processed or queued.
        } catch (\Throwable $e) {
            Log::error('integration.woo.store_failed', [
                'connection_id' => $conn->id,
                'error'         => $e->getMessage(),
            ]);
        }

        // Always 200 — WooCommerce must not auto-disable the webhook.
        return response('', 200);
    }
}
