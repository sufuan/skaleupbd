<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Models\AppNotification;
use App\Models\Business;
use App\Models\CourierMethod;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PhoneNormalizer;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * External API intake (Surface A security model, §9.1): sellers' websites
 * push orders into the same Order List. Auth: x-api-key + businessId.
 */
class ExternalOrderController extends Controller
{
    public function __construct(private readonly OrderService $orders, private readonly SettingsService $settings) {}

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

    /**
     * The shop's usual delivery charge when the caller omits
     * deliveryCharge: the zone split every other intake applies.
     * The district is the one the payload names, else the one its
     * address names (OrderService::deriveDistrict — the same
     * extraction that will be stored on the order); the zone test
     * is CourierMethod::isInsideDhaka, the platform's one
     * definition. An address naming no district is not inside
     * Dhaka by that test, so it takes the outside figure, exactly
     * as the chat-order summary does.
     */
    private function defaultDeliveryCharge(int $businessId, array $data): float
    {
        $district = isset($data['district']) && trim((string) $data['district']) !== ''
            ? (string) $data['district']
            : OrderService::deriveDistrict($data['customerAddress'] ?? null);

        $inside = CourierMethod::isInsideDhaka($district);

        return (float) $this->settings->bizGet(
            $businessId,
            $inside ? "business.{$businessId}.default_delivery_cost" : "business.{$businessId}.default_delivery_cost_outside",
            $inside ? 'business.default_delivery_cost' : 'business.default_delivery_cost_outside',
            $inside ? 60 : 120,
        );
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
        // A shop that is not approved (pending, rejected or suspended)
        // must not trade (Hunt Y4 F1): its key used to keep creating
        // orders - and burning points - after the platform rejected
        // the seller. Null/empty status predates the approval flow
        // and reads as approved, matching SellerApprovalGate.
        $bizStatus = $business->status;
        if ($bizStatus !== null && $bizStatus !== '' && $bizStatus !== 'approved') {
            return $this->envelope(false, 403, 'This shop is not active and cannot take orders right now.');
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
            'products.*.variantId' => ['nullable', 'integer'],
            'products.*.variantName' => ['nullable', 'string', 'max:120'],
            'products.*.name' => ['nullable', 'string', 'max:160'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
            'products.*.price' => ['required', 'numeric', 'min:0'],
        ]);

        if (! PhoneNormalizer::isValid($data['customerNumber'])) {
            return $this->envelope(false, 422, 'customerNumber is not a valid mobile number');
        }

        // Z3-OBS1: a line that NAMES a product id must name one of the
        // caller's own products. OrderService resolves product ids
        // inside the key's business, so a foreign or mistyped id used
        // to degrade silently into an unlinked line at the
        // caller-supplied price - the integration never learned its
        // catalogue was wrong. Refuse the whole order, naming the bad
        // id(s). Lines with no productId stay free-text, as before.
        $namedIds = collect($data['products'])->pluck('productId')->filter(fn ($id) => $id !== null)->unique()->values();
        if ($namedIds->isNotEmpty()) {
            $ownIds = \App\Models\Product::where('business_id', $business->id)
                ->whereIn('id', $namedIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $badIds = $namedIds->map(fn ($id) => (int) $id)->diff($ownIds)->values();
            if ($badIds->isNotEmpty()) {
                return $this->envelope(false, 422, $badIds->count() === 1
                    ? 'Product '.$badIds->first().' does not belong to this shop.'
                    : 'Products '.$badIds->implode(', ').' do not belong to this shop.');
            }
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
            // Y1-F4: an omitted deliveryCharge means "the shop's
            // usual charge", not free delivery. The usual charge is
            // zone-split, exactly as the chat-order and storefront
            // paths quote it: the district the order names (or the
            // one its address names) picks the shop's inside-Dhaka
            // or outside-Dhaka Business Profile figure. (This used
            // to fall back to the inside figure unconditionally, so
            // an outside-Dhaka API order that omitted the charge was
            // undercharged.) An explicitly sent value (including 0)
            // is respected.
            'delivery_charge' => $data['deliveryCharge'] ?? $this->defaultDeliveryCharge($business->id, $data),
            'advance' => $data['advance'] ?? 0,
            'is_preorder' => (bool) ($data['isPreorder'] ?? false),
            'external_ref' => $data['externalRef'] ?? null,
            'idempotency_key' => $idem !== '' ? $idem : null,
            'ip' => $request->ip(),
            'items' => collect($data['products'])->map(fn ($p) => [
                'product_id' => $p['productId'] ?? null,
                // R3: a line may address a variant of the product, by id
                // or by name - OrderService resolves either onto the
                // order line, so approval and stock moves use the
                // variant's own pool. Without this the API could not
                // sell variant stock at all.
                'variant_id' => $p['variantId'] ?? null,
                'variant_name' => $p['variantName'] ?? null,
                // A line that omits its name (or sends it blank) must
                // reach OrderService as null so its own fallback can
                // fill the linked product's name (name ?? product
                // name ?? 'Item'). Hard-coding 'Item' here hid the
                // product name on every API order whose caller did
                // not repeat it — the order read "Item 2x 250" for a
                // linked Plain Cap.
                'name' => isset($p['name']) && trim((string) $p['name']) !== '' ? $p['name'] : null,
                'qty' => $p['quantity'],
                'price' => $p['price'],
            ])->all(),
            ], null, 'External API');
        } catch (\App\Exceptions\InsufficientTokensException $e) {
            return $this->envelope(false, 402, $e->getMessage());
        } catch (\Illuminate\Database\QueryException $e) {
            // Z1-4, the racing twin: both POSTs passed the dedup
            // checks above and the unique (business_id, external_ref)
            // / (business_id, idempotency_key) indexes stopped this
            // one at the insert. A duplicate-key outcome means the
            // original order now exists - return it, exactly like
            // the sequential-duplicate path above. Any other database
            // error is not a duplicate and keeps flying.
            $existing = null;
            if ($idem !== '') {
                $existing = Order::where('business_id', $business->id)->where('idempotency_key', $idem)->first();
            }
            if (! $existing && ! empty($data['externalRef'])) {
                $existing = Order::where('business_id', $business->id)->where('external_ref', $data['externalRef'])->first();
            }
            if ($existing) {
                return $this->envelope(true, 200, 'Duplicate request - order already exists', [
                    'orderId' => $existing->id, 'invoice' => $existing->invoice_no,
                ]);
            }
            throw $e;
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
