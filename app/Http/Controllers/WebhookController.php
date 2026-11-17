<?php

namespace App\Http\Controllers;

use App\Models\CourierMethod;
use App\Models\Order;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class WebhookController extends Controller
{
    /**
     * Steadfast webhook receiver — the URL saved on each connection
     * (/api/webhooks/steadfast/dm/{id}). Steadfast POSTs JSON when a parcel
     * moves or a payout happens; we answer 2xx within their 5-second window.
     * Auth: the connection's optional webhook token rides as
     * Authorization: Bearer <token> and signs the raw body into X-Signature
     * (HMAC-SHA256, hex). Retries of the same event carry the same
     * Idempotency-Key and are answered without re-processing.
     */
    public function steadfast(Request $request, CourierMethod $method, BookingService $booking)
    {
        $token = (string) (($method->credentials ?? [])['webhook_token'] ?? '');
        if ($token !== '') {
            $bearer = (string) $request->bearerToken();
            $expected = hash_hmac('sha256', $request->getContent(), $token);
            $given = (string) $request->header('X-Signature', '');
            if (! hash_equals($token, $bearer) || ! hash_equals($expected, $given)) {
                return response()->json(['status' => 'unauthorized'], 401);
            }
        }

        $idem = (string) $request->header('Idempotency-Key', '');
        if ($idem !== '') {
            $cacheKey = 'sf_webhook_' . $method->id . '_' . md5($idem);
            if (Cache::has($cacheKey)) {
                return response()->json(['status' => 'success']);
            }
            Cache::put($cacheKey, true, now()->addDay());
        }

        $payload = $request->json()->all();
        if (($payload['notification_type'] ?? null) === 'delivery_status' && ! empty($payload['status'])) {
            $order = Order::where('business_id', $method->business_id)
                ->where(function ($q) use ($payload) {
                    if (! empty($payload['consignment_id'])) {
                        $q->where('consignment_id', (string) $payload['consignment_id']);
                    }
                    if (! empty($payload['invoice'])) {
                        $q->orWhere('invoice_no', (string) $payload['invoice']);
                    }
                })
                ->first();
            if ($order) {
                // Lifecycle changes need an actor; the business's admin stands in for the system.
                $actor = User::where('business_id', $method->business_id)->where('is_admin', true)->first();
                $booking->applyCourierStatus($order, (string) $payload['status'], $actor);
            }
        }
        // Other event types (tracking_update, payment_request, return_request, …)
        // are accepted and kept for later wiring; the pull flows cover the money today.

        return response()->json(['status' => 'success']);
    }

    /**
     * Pathao webhook receiver — the URL saved on each connection
     * (/api/webhooks/pathao/dm/{id}). Pathao sends its events with the
     * connection's webhook secret as the X-PATHAO-Signature header (the secret
     * itself, not a signature). The integration test event must be answered
     * 202 with that same secret echoed in the
     * X-Pathao-Merchant-Webhook-Integration-Secret header.
     */
    public function pathao(Request $request, CourierMethod $method, BookingService $booking)
    {
        $secret = (string) (($method->credentials ?? [])['webhook_secret'] ?? '');
        $payload = $request->json()->all();
        $event = (string) ($payload['event'] ?? '');

        if ($event === 'webhook_integration') {
            return response()->json(['status' => 'success'], 202)
                ->header('X-Pathao-Merchant-Webhook-Integration-Secret', $secret);
        }
        if ($secret !== '' && ! hash_equals($secret, (string) $request->header('X-PATHAO-Signature', ''))) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $consignmentId = (string) ($payload['consignment_id'] ?? $payload['data']['consignment_id'] ?? '');
        $invoice = (string) ($payload['merchant_order_id'] ?? $payload['data']['merchant_order_id'] ?? '');
        if ($consignmentId !== '' || $invoice !== '') {
            $order = Order::where('business_id', $method->business_id)
                ->where(function ($q) use ($consignmentId, $invoice) {
                    if ($consignmentId !== '') {
                        $q->where('consignment_id', $consignmentId);
                    }
                    if ($invoice !== '') {
                        $q->orWhere('invoice_no', $invoice);
                    }
                })
                ->first();
            if ($order) {
                $status = (string) ($payload['order_status_slug'] ?? $payload['order_status'] ?? $payload['data']['order_status_slug'] ?? '');
                if ($status === '') {
                    $status = match ($event) {
                        'delivered' => 'delivered',
                        'partial_delivery' => 'partial_delivered',
                        'returned_to_merchant' => 'returned_to_merchant',
                        default => '',
                    };
                }
                if ($status !== '') {
                    $actor = User::where('business_id', $method->business_id)->where('is_admin', true)->first();
                    $booking->applyCourierStatus($order, $status, $actor);
                }
            }
        }

        return response()->json(['status' => 'success'], 202);
    }

    /**
     * Carrybee: POST /api/webhooks/carrybee/dm/{method}
     * The seller registers this URL + a secret in the panel's Webhook
     * Integration form. The integration event {"event":"webhook.integration"}
     * must be answered 202 with the X-CB-Webhook-Integration-Header echoing
     * the secret; real events carry that same header for verification.
     * Event names: order.delivered / order.partial-delivery / order.returned /
     * order.returned-to-merchant / order.paid carry the money-relevant
     * transitions; every other event is recorded as the courier status only.
     */
    public function carrybee(Request $request, CourierMethod $method)
    {
        abort_unless($method->courier_type === 'CARRYBEE' && $method->is_active, 404);
        $secret = (string) (($method->credentials ?? [])['webhook_secret'] ?? '');
        $event = (string) $request->input('event', '');

        if ($event === 'webhook.integration') {
            return response()->json(['received' => true], 202)
                ->header('X-CB-Webhook-Integration-Header', $secret);
        }
        if ($secret !== '' && ! hash_equals($secret, (string) $request->header('X-CB-Webhook-Integration-Header', ''))) {
            return response()->json(['error' => 'bad signature'], 401);
        }

        $consignment = (string) $request->input('consignment_id', '');
        $invoice = (string) $request->input('merchant_order_id', '');
        $order = Order::where('business_id', $method->business_id)
            ->where(fn ($q) => $q->where('consignment_id', $consignment)->orWhere('invoice_no', $invoice))
            ->first();
        if ($order) {
            $status = match ($event) {
                'order.delivered' => 'delivered',
                'order.partial-delivery' => 'partial_delivered',
                'order.returned', 'order.returned-to-merchant', 'order.paid-return' => 'returned',
                default => $event,
            };
            $actor = User::where('business_id', $method->business_id)->where('is_admin', true)->first();
            app(BookingService::class)->applyCourierStatus($order, $status, $actor);
        }

        return response()->json(['received' => true], 202);
    }

    /**
     * Paperfly: POST /api/webhooks/paperfly/dm/{method}
     * The seller registers this URL + a Secret Key in the panel's Developer
     * Guide → Webhooks form. Events arrive as JSON with an X-Webhook-Signature
     * header (HMAC-SHA256 of the raw body with that secret). Payload carries
     * tracking_id + merchant_reference + status; order.delivered and
     * order.returned drive the money-relevant transitions, everything else
     * (incl. payment.received / payment.failed) is recorded.
     */
    public function paperfly(Request $request, CourierMethod $method)
    {
        abort_unless($method->courier_type === 'PAPERFLY' && $method->is_active, 404);
        $secret = (string) (($method->credentials ?? [])['webhook_secret'] ?? '');
        if ($secret !== '') {
            $expected = hash_hmac('sha256', $request->getContent(), $secret);
            if (! hash_equals($expected, (string) $request->header('X-Webhook-Signature', ''))) {
                return response()->json(['error' => 'bad signature'], 401);
            }
        }

        $tracking = (string) $request->input('tracking_id', '');
        $reference = (string) $request->input('merchant_reference', '');
        $order = Order::where('business_id', $method->business_id)
            ->where(fn ($q) => $q->where('tracking_id', $tracking)->orWhere('consignment_id', $tracking)->orWhere('invoice_no', $reference))
            ->first();
        if ($order) {
            $event = (string) $request->input('event', '');
            $status = match ($event) {
                'order.delivered' => 'delivered',
                'order.returned' => 'returned',
                default => (string) ($request->input('status') ?: $event),
            };
            $actor = User::where('business_id', $method->business_id)->where('is_admin', true)->first();
            app(BookingService::class)->applyCourierStatus($order, $status, $actor);
        }

        return response()->json(['received' => true], 200);
    }
}
