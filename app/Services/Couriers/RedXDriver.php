<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * RedX Open API — official documentation at redx.com.bd/developer-api
 * (read in full 2026-11-18). Auth: header "API-ACCESS-TOKEN: Bearer <token>".
 * Production base https://openapi.redx.com.bd/v1.0.0-beta; sandbox base
 * https://sandbox.redx.com.bd/v1.0.0-beta with its OWN token (Settings tick).
 * Endpoints: POST /parcel · GET /parcel/info/{id} (status lives here) ·
 * GET /parcel/track/{id} (history only) · PATCH /parcels (cancel) ·
 * GET /areas (+post_code/district_name) · POST /pickup/store ·
 * GET /pickup/stores · GET /pickup/store/info/{id} ·
 * GET /charge/charge_calculator. Status updates also arrive by webhook
 * (callback URL, credentials ride in the query string — no signature).
 * NO payments/balance endpoint and NO fraud endpoint (Class B).
 */
class RedXDriver implements CourierDriver
{
    private function base(CourierMethod $method): string
    {
        $c = $method->credentials ?? [];

        return ! empty($c['sandbox']) && $c['sandbox'] !== '0'
            ? 'https://sandbox.redx.com.bd/v1.0.0-beta'
            : 'https://openapi.redx.com.bd/v1.0.0-beta';
    }

    private function http(CourierMethod $method)
    {
        $c = $method->credentials ?? [];

        return Http::timeout(8)->connectTimeout(4)->withHeaders([
            'API-ACCESS-TOKEN' => 'Bearer ' . (string) ($c['access_token'] ?? ''),
            'Content-Type' => 'application/json',
        ]);
    }

    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array
    {
        $c = $method->credentials ?? [];
        try {
            $area = $this->resolveArea($order, $method);
            if ($area === null) {
                return ['ok' => false, 'error' => 'RedX needs a delivery area ID for ' . ($order->district ?: 'this order') . ' - set the Default Area ID in Settings → Courier → RedX'];
            }
            $weightGrams = max(1, (int) round(((float) ($c['default_weight'] ?? 0.5)) * 1000));
            $payload = [
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'customer_address' => trim(($order->address ?? '') . ' ' . ($order->area ?? '') . ' ' . ($order->district ?? '')),
                'delivery_area' => $area['name'],
                'delivery_area_id' => $area['id'],
                'merchant_invoice_id' => $order->invoice,
                'cash_collection_amount' => (string) (int) round($order->codAmount()),
                'parcel_weight' => $weightGrams,
                'value' => (string) (int) round($order->grand_total),
                'instruction' => $order->delivery_note ?? $method->default_shipping_note,
                'parcel_details_json' => $order->items->map(fn ($i) => [
                    'name' => $i->product_name ?? $i->name,
                    'category' => $i->category ?? 'General',
                    'value' => (int) round(($i->price ?? 0) * ($i->qty ?? 1)),
                ])->values()->all(),
            ];
            $storeId = $order->pickupStore?->store_id ?? ($c['pickup_store'] ?? null);
            if (! empty($storeId) && is_numeric($storeId)) {
                $payload['pickup_store_id'] = (int) $storeId;
            }
            $res = $this->http($method)->post($this->base($method) . '/parcel', $payload);
            $body = $res->json() ?? [];
            $tracking = $body['tracking_id'] ?? $body['data']['tracking_id'] ?? null;
            if ($res->successful() && $tracking) {
                return ['ok' => true, 'tracking_id' => (string) $tracking, 'consignment_id' => (string) $tracking, 'raw' => $body];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'RedX rejected the booking (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach RedX: ' . $e->getMessage()];
        }
    }

    /**
     * The create call REQUIRES a delivery_area_id. Resolve it from RedX's own
     * area list for the order's district (cached a day), matching the order's
     * area name; fall back to the district's first area, then to the Settings
     * default. Returns ['id' => int, 'name' => string] or null.
     */
    private function resolveArea(Order $order, CourierMethod $method): ?array
    {
        $c = $method->credentials ?? [];
        $district = trim((string) $order->district);
        if ($district !== '') {
            $areas = Cache::remember('redx-areas-' . $method->id . '-' . md5($district), 86400, function () use ($method, $district) {
                try {
                    $res = $this->http($method)->get($this->base($method) . '/areas', ['district_name' => $district]);

                    return ($res->json() ?? [])['areas'] ?? [];
                } catch (\Throwable) {
                    return [];
                }
            });
            if (! empty($areas)) {
                $want = strtolower(trim((string) $order->area));
                foreach ($areas as $a) {
                    if ($want !== '' && strtolower(trim((string) ($a['name'] ?? ''))) === $want) {
                        return ['id' => (int) $a['id'], 'name' => (string) $a['name']];
                    }
                }
                $first = $areas[0];

                return ['id' => (int) ($first['id'] ?? 0), 'name' => (string) ($order->area ?: ($first['name'] ?? ''))];
            }
        }
        if (! empty($c['default_area_id'])) {
            return ['id' => (int) $c['default_area_id'], 'name' => (string) ($order->area ?: ($c['default_area'] ?? ''))];
        }

        return null;
    }

    /** Parcel info — the status lives here (the track endpoint is history only). */
    public function getParcelInfo(Order $order, CourierMethod $method): ?array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . '/parcel/info/' . ($order->tracking_id ?: $order->consignment_id));
            $parcel = ($res->json() ?? [])['parcel'] ?? null;

            return is_array($parcel) ? $parcel : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getStatus(Order $order, CourierMethod $method): array
    {
        $parcel = $this->getParcelInfo($order, $method);
        if ($parcel && ! empty($parcel['status'])) {
            return ['ok' => true, 'courier_status' => (string) $parcel['status'], 'raw' => $parcel];
        }

        return ['ok' => false, 'error' => 'Status check failed'];
    }

    /** Tracking history: [{message_en, message_bn, time}] from /parcel/track/{id}. */
    public function getTrackingHistory(Order $order, CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . '/parcel/track/' . ($order->tracking_id ?: $order->consignment_id));

            return ($res->json() ?? [])['tracking'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function cancelBooking(Order $order, CourierMethod $method): array
    {
        try {
            // Cancellation is an Update Parcel call: status -> cancelled.
            $res = $this->http($method)->patch($this->base($method) . '/parcels', [
                'entity_type' => 'parcel-tracking-id',
                'entity_id' => $order->tracking_id ?: $order->consignment_id,
                'update_details' => [
                    'property_name' => 'status',
                    'new_value' => 'cancelled',
                    'reason' => 'Cancelled by the seller',
                ],
            ]);
            $body = $res->json() ?? [];

            return ['ok' => $res->successful() && ($body['success'] ?? false) === true];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    public function fraudCheck(string $phone, CourierMethod $method): ?array
    {
        return null; // No fraud/success-rate endpoint in the official docs.
    }

    public function testConnection(CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . '/areas');
            return $res->successful()
                ? ['ok' => true, 'message' => 'Connected - RedX accepted the access token']
                : ['ok' => false, 'message' => 'RedX did not accept the access token (HTTP ' . $res->status() . ')'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach RedX: ' . $e->getMessage()];
        }
    }

    public function syncStores(CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . '/pickup/stores');
            $stores = ($res->json() ?? [])['pickup_stores'] ?? [];

            return collect($stores)->map(fn ($s) => [
                'store_id' => (string) ($s['id'] ?? ''),
                'name' => ($s['name'] ?? 'RedX Store') . (isset($s['area_name']) ? ' - ' . $s['area_name'] : ''),
                'address' => $s['address'] ?? null,
                'is_default' => false,
            ])->filter(fn ($s) => $s['store_id'] !== '')->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Create a pickup store: POST /pickup/store {name, phone, address, area_id}. */
    public function createStore(CourierMethod $method, array $data): array
    {
        try {
            $res = $this->http($method)->post($this->base($method) . '/pickup/store', [
                'name' => $data['name'] ?? '',
                'phone' => $data['phone'] ?? '',
                'address' => $data['address'] ?? '',
                'area_id' => (int) ($data['area_id'] ?? 0),
            ]);
            $body = $res->json() ?? [];
            if ($res->successful() && ! empty($body['id'])) {
                return ['ok' => true, 'store_id' => (string) $body['id'], 'raw' => $body];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'Store creation failed (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach RedX: ' . $e->getMessage()];
        }
    }

    /** All areas, or filtered by post code / district name. */
    public function getAreas(CourierMethod $method, ?string $postCode = null, ?string $district = null): array
    {
        try {
            $query = array_filter(['post_code' => $postCode, 'district_name' => $district]);
            $res = $this->http($method)->get($this->base($method) . '/areas', $query);

            return ($res->json() ?? [])['areas'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Per-parcel price quote: GET /charge/charge_calculator. */
    public function calculateCharge(CourierMethod $method, int $deliveryAreaId, int $pickupAreaId, float $codAmount, int $weightGrams): ?array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . '/charge/charge_calculator', [
                'delivery_area_id' => $deliveryAreaId,
                'pickup_area_id' => $pickupAreaId,
                'cash_collection_amount' => $codAmount,
                'weight' => $weightGrams,
            ]);
            $body = $res->json() ?? [];
            if (isset($body['deliveryCharge'])) {
                return ['delivery_charge' => (float) $body['deliveryCharge'], 'cod_charge' => (float) ($body['codCharge'] ?? 0)];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getPayments(CourierMethod $method): array
    {
        return []; // No payments API — Class B (invoice upload / one-line entries).
    }

    public function getPaymentConsignments(CourierMethod $method, string $paymentId): array
    {
        return [];
    }

    public function parcelCollection(Order $order, CourierMethod $method): ?array
    {
        $p = $this->getParcelInfo($order, $method);
        if (! $p) {
            return null;
        }
        $delivered = strtolower((string) ($p['status'] ?? '')) === 'delivered';

        return [
            'collected' => $delivered && isset($p['cash_collection_amount']) ? (float) $p['cash_collection_amount'] : null,
            'cod_fee' => null, // not broken out per parcel in parcel info
            'delivery_fee' => isset($p['charge']) ? (float) $p['charge'] : null,
            'payment_status' => $p['status'] ?? null,
        ];
    }
}
