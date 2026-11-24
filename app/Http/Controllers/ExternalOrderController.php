<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\AppNotification;
use App\Models\Business;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * External API intake (Surface A security model, §9.1): sellers' websites
 * push orders into the same Order List. Auth: x-api-key + businessId.
 */
class ExternalOrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    private function envelope(bool $ok, int $code, string $message, ?array $data = null): JsonResponse
    {
        return response()->json([
            'success' => $ok,
            'status' => $ok ? 'success' : 'error',
            'message' => $message,
            'error' => $ok ? null : $message,
            'statusCode' => $code,
            'requestId' => 'req_'.bin2hex(random_bytes(6)),
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ], $code);
    }

    public function store(Request $request): JsonResponse
    {
        $key = (string) $request->header('x-api-key', '');
        $businessId = (string) $request->header('businessId', $request->input('businessId', ''));

        // findByRawKey is the shared sha256 lookup; it excludes revoked
        // keys and stamps last_used_at.
        $apiKey = $key !== '' ? ApiKey::findByRawKey($key) : null;
        if (! $apiKey) {
            return $this->envelope(false, 401, 'Invalid API key');
        }
        $business = Business::find($apiKey->business_id);
        if (! $business || $business->public_id !== $businessId) {
            return $this->envelope(false, 404, 'Business not found');
        }
        if (! $apiKey->grants('orders.write')) {
            return $this->envelope(false, 403, 'This key does not have permission to write orders.');
        }

        $idem = (string) $request->header('x-idempotency-key', '');
        if ($idem !== '') {
            $existing = Order::where('business_id', $business->id)->where('idempotency_key', $idem)->first();
            if ($existing) {
                return $this->envelope(true, 200, 'Duplicate request - order already exists', [
                    'orderId' => $existing->id, 'invoice' => $existing->invoice_no,
                ]);
            }
        }

        $data = $request->validate([
            'customerName' => ['required', 'string', 'max:120'],
            'customerNumber' => ['required', 'string', 'max:25'],
            'customerAddress' => ['nullable', 'string', 'max:500'],
            'district' => ['nullable', 'string', 'max:80'],
            'area' => ['nullable', 'string', 'max:80'],
            'orderSource' => ['nullable', 'string', 'max:40'],
            'deliveryMethodName' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'deliveryCharge' => ['nullable', 'numeric', 'min:0'],
            'advance' => ['nullable', 'numeric', 'min:0'],
            'isPreorder' => ['nullable', 'boolean'],
            'webStatus' => ['nullable', 'in:processing,incomplete'],
            'externalRef' => ['nullable', 'string', 'max:80'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.productId' => ['nullable', 'integer'],
            'products.*.name' => ['nullable', 'string', 'max:160'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
            'products.*.price' => ['required', 'numeric', 'min:0'],
        ]);

        if (! PhoneNormalizer::isValid($data['customerNumber'])) {
            return $this->envelope(false, 422, 'customerNumber is not a valid mobile number');
        }

        // Dedup on the caller's own order reference, not only on the
        // optional idempotency header: externalRef is the identifier every
        // caller already sends (the status lookup keys on it too), and the
        // in-app docs never mention the header. A retried POST must return
        // the original order - never a second order or a second point.
        if (! empty($data['externalRef'])) {
            $existing = Order::where('business_id', $business->id)->where('external_ref', $data['externalRef'])->first();
            if ($existing) {
                return $this->envelope(true, 200, 'Duplicate request - order already exists', [
                    'orderId' => $existing->id, 'invoice' => $existing->invoice_no,
                ]);
            }
        }

        try {
            $order = $this->orders->create($business->id, [
            'customer_name' => $data['customerName'],
            'customer_phone' => $data['customerNumber'],
            'customer_address' => $data['customerAddress'] ?? null,
            'district' => $data['district'] ?? null,
            'area' => $data['area'] ?? null,
            'source' => $data['orderSource'] ?? 'WEB',
            'site' => $business->name.' website',
            'status' => ($data['webStatus'] ?? 'processing') === 'incomplete' ? 'Incomplete' : 'Pending',
            'delivery_method' => $data['deliveryMethodName'] ?? null,
            'delivery_note' => $data['note'] ?? null,
            'discount' => $data['discount'] ?? 0,
            'delivery_charge' => $data['deliveryCharge'] ?? 0,
            'advance' => $data['advance'] ?? 0,
            'is_preorder' => (bool) ($data['isPreorder'] ?? false),
            'external_ref' => $data['externalRef'] ?? null,
            'idempotency_key' => $idem !== '' ? $idem : null,
            'ip' => $request->ip(),
            'items' => collect($data['products'])->map(fn ($p) => [
                'product_id' => $p['productId'] ?? null,
                'name' => $p['name'] ?? 'Item',
                'qty' => $p['quantity'],
                'price' => $p['price'],
            ])->all(),
            ], null, 'External API');
        } catch (\App\Exceptions\InsufficientTokensException $e) {
            return $this->envelope(false, 402, $e->getMessage());
        }

        AppNotification::create(['business_id' => $order->business_id, 'kind' => 'order', 'title' => "New order {$order->invoice_no}",
            'body' => "{$order->customer_name} · ৳".number_format((float) $order->grand_total), 'link' => "/orders/{$order->id}"]);

        return $this->envelope(true, 201, 'Order created', [
            'orderId' => $order->id, 'invoice' => $order->invoice_no, 'courierId' => null,
        ]);
    }

    /**
     * GET /api/external/v1/orders/{externalRef} — status + tracking lookup
     * for an order the caller's site created earlier (scope: orders.read).
     * Same auth semantics as store(): the business comes from the key, and
     * a businessId header, when sent, must name that same business.
     * Zero-points rule: at zero points our services stop, reads included,
     * so a suspended shop gets a plain 503 (its own site is unaffected).
     */
    public function status(Request $request, string $externalRef): JsonResponse
    {
        $key = (string) $request->header('x-api-key', '');
        $apiKey = $key !== '' ? ApiKey::findByRawKey($key) : null;
        if (! $apiKey) {
            return $this->envelope(false, 401, 'Invalid API key');
        }
        $business = Business::find($apiKey->business_id);
        $claimed = (string) $request->header('businessId', $request->input('businessId', ''));
        if (! $business || ($claimed !== '' && $business->public_id !== $claimed)) {
            return $this->envelope(false, 404, 'Business not found');
        }
        if (! $apiKey->grants('orders.read')) {
            return $this->envelope(false, 403, 'This key does not have permission to read orders.');
        }
        if (! app(\App\Services\Integrations\IntegrationHost::class)->hasPoints($business->id)) {
            return $this->envelope(false, 503, 'This connection is suspended because the shop has run out of points. The order on your side is unaffected. Please try again after the shop tops up.');
        }

        $order = Order::where('business_id', $business->id)
            ->where('external_ref', $externalRef)
            ->first(['id', 'invoice_no', 'status', 'delivery_method', 'tracking_id', 'consignment_id', 'updated_at']);
        if (! $order) {
            return $this->envelope(false, 404, 'Order not found.');
        }

        return $this->envelope(true, 200, 'Order found', [
            'orderId' => $order->id,
            'invoice' => $order->invoice_no,
            'status' => $order->status,
            'courierName' => $order->delivery_method,
            'trackingNumber' => $order->tracking_id ?? $order->consignment_id,
            'updatedAt' => $order->updated_at?->toIso8601String(),
        ]);
    }
}
