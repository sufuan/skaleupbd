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
}
