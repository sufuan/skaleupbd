<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;
use Illuminate\Support\Facades\Http;

class CarrybeeDriver implements CourierDriver
{
    /**
     * Production host is developers.carrybee.com; the sandbox host is
     * sandbox.carrybee.com. Per the merchant panel's API Credentials page
     * (read 2026-11-17), BOTH environments' credentials are pre-generated per
     * business and shown in the panel — the Sandbox Mode switch changes the
     * host; the credentials pasted must be that environment's own set.
     */
    private function base(CourierMethod $method): string
    {
        return ! empty(($method->credentials ?? [])['sandbox'])
            ? 'https://sandbox.carrybee.com'
            : 'https://developers.carrybee.com';
    }

    private function http(CourierMethod $method)
    {
        $c = $method->credentials ?? [];

        return Http::timeout(8)->connectTimeout(4)->withHeaders([
            'Client-ID' => (string) ($c['client_id'] ?? ''),
            'Client-Secret' => (string) ($c['client_secret'] ?? ''),
            'Client-Context' => (string) ($c['client_context'] ?? ''),
            'Content-Type' => 'application/json',
        ]);
    }

    /** The v2 create-order payload, shared by single + bulk booking. */
    private function orderPayload(Order $order, CourierMethod $method, array $opts = []): array
    {
        $c = $method->credentials ?? [];
        $note = $order->delivery_note ?? $method->default_shipping_note;
        $payload = [
            'store_id' => $opts['store_id'] ?? ($order->pickupStore?->store_id ?? ($c['store_id'] ?? null)),
            'merchant_order_id' => $order->invoice,
            'recipient_name' => $order->customer_name,
            'recipient_phone' => $order->customer_phone,
            // The API accepts 10–200 characters of recipient address.
            'recipient_address' => mb_substr(trim(($order->address ?? '') . ' ' . ($order->area ?? '') . ' ' . ($order->district ?? '')), 0, 200),
            'collectable_amount' => (int) round($order->codAmount()),
            // The API wants item_weight in GRAMS (integer, 1–25,000); the Settings field is kg.
            'item_weight' => max(1, (int) round(((float) ($c['default_weight'] ?? 0.5)) * 1000)),
            'item_quantity' => max(1, (int) ($order->relationLoaded('items') ? $order->items->sum('qty') : 1)),
            'special_instruction' => $note !== null ? mb_substr((string) $note, 0, 255) : null,
        ];
        if (! empty($order->alt_phone)) {
            // The create contract spells this 'recipient_secendary_phone' while the
            // details response uses 'recipient_secondary_phone' — send both.
            $payload['recipient_secendary_phone'] = $order->alt_phone;
            $payload['recipient_secondary_phone'] = $order->alt_phone;
        }
        $description = $order->relationLoaded('items')
            ? $order->items->map(fn ($i) => $i->product_name)->filter()->implode(', ')
            : '';
        if ($description !== '') {
            $payload['product_description'] = mb_substr($description, 0, 255);
        }
        // product_type / delivery_type are NUMERIC codes in the API — forward only numeric values.
        foreach (['product_type', 'delivery_type'] as $k) {
            if (isset($c[$k]) && is_numeric($c[$k])) {
                $payload[$k] = (int) $c[$k];
            }
        }
        // Optional geography — only when the seller configured default IDs.
        foreach (['city_id' => 'default_city_id', 'zone_id' => 'default_zone_id', 'area_id' => 'default_area_id'] as $field => $cred) {
            if (! empty($c[$cred]) && is_numeric($c[$cred])) {
                $payload[$field] = (int) $c[$cred];
            }
        }

        return $payload;
    }

    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array
    {
        try {
            $res = $this->http($method)->post($this->base($method) . '/api/v2/orders', $this->orderPayload($order, $method, $opts));
            $body = $res->json() ?? [];
            $data = $body['data'] ?? $body;
            $id = $data['order']['consignment_id'] ?? $data['consignment_id'] ?? $data['id'] ?? $data['order_id'] ?? null;
            if ($res->successful() && $id) {
                return ['ok' => true, 'tracking_id' => (string) $id, 'consignment_id' => (string) $id, 'raw' => $body];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'Carrybee rejected the booking (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach :courier: :detail', ['courier' => 'Carrybee', 'detail' => $e->getMessage()])];
        }
    }

    private function details(Order $order, CourierMethod $method): ?array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . '/api/v2/orders/' . ($order->consignment_id ?: $order->tracking_id) . '/details');
            $body = $res->json() ?? [];

            return $res->successful() ? ($body['data'] ?? $body) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getStatus(Order $order, CourierMethod $method): array
    {
        $d = $this->details($order, $method);
        $status = $d['transfer_status'] ?? $d['status'] ?? null; // the details contract carries transfer_status
        if ($d && ! empty($status)) {
            return ['ok' => true, 'courier_status' => (string) $status, 'raw' => $d];
        }

        return ['ok' => false, 'error' => __('Status check failed')];
    }

    public function cancelBooking(Order $order, CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->post($this->base($method) . '/api/v2/orders/' . ($order->consignment_id ?: $order->tracking_id) . '/cancel', [
                'cancellation_reason' => 'Cancelled by the seller',
            ]);

            return ['ok' => $res->successful()];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    public function fraudCheck(string $phone, CourierMethod $method): ?array
    {
        return null;
    }

    public function testConnection(CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . '/api/v2/stores');
            return $res->successful()
                ? ['ok' => true, 'message' => __('Connected - Carrybee accepted the credentials')]
                : ['ok' => false, 'message' => __('Carrybee did not accept these credentials (HTTP :status)', ['status' => $res->status()])];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => __('Could not reach :courier: :detail', ['courier' => 'Carrybee', 'detail' => $e->getMessage()])];
        }
    }

    public function syncStores(CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . '/api/v2/stores');
            $rows = $res->json('data.stores') ?? $res->json('data') ?? [];

            return collect(is_array($rows) ? $rows : [])->map(fn ($s) => [
                'store_id' => (string) ($s['id'] ?? $s['store_id'] ?? ''),
                'name' => (string) ($s['name'] ?? $s['store_name'] ?? ''),
            ])->filter(fn ($s) => $s['store_id'] !== '')->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function getPayments(CourierMethod $method): array
    {
        return []; // No settlement API — per-parcel polling + emailed statement (Class B).
    }

    public function getPaymentConsignments(CourierMethod $method, string $paymentId): array
    {
        return [];
    }

    public function parcelCollection(Order $order, CourierMethod $method): ?array
    {
        $d = $this->details($order, $method);
        if (! $d) {
            return null;
        }

        return [
            'collected' => isset($d['collected_amount']) ? (float) $d['collected_amount'] : null,
            'cod_fee' => isset($d['cod_fee']) ? (float) $d['cod_fee'] : null,
            'delivery_fee' => isset($d['delivery_fee']) ? (float) $d['delivery_fee'] : null,
            'payment_status' => $d['payment_status'] ?? null,
        ];
    }

    // ---- The rest of Carrybee's v2 API surface ----
    // Shapes follow the real API's contracts. Carrybee publishes NO public API
    // documentation (docs + credentials live inside the merchant panel, via
    // KAM/support), so these stay fixture-verified until a real account proves
    // them live — see Real-Account-Pending-Checks.md.

    /**
     * POST /orders-bulk — the API takes a BARE array of order payloads and
     * answers {error, message, data: null}: no per-order breakdown exists, so
     * every order is reported accepted/not-accepted as one batch result.
     */
    public function createBulkBookings(array $orders, CourierMethod $method, array $opts = []): array
    {
        $payloads = collect($orders)->map(fn (Order $o) => $this->orderPayload($o, $method, $opts))->all();
        try {
            $res = $this->http($method)->post($this->base($method) . '/api/v2/orders-bulk', $payloads);
            $body = $res->json() ?? [];
            $ok = $res->successful() && ($body['error'] ?? true) === false;

            return collect($orders)->map(fn (Order $o) => [
                'invoice' => $o->invoice, 'ok' => $ok, 'tracking_id' => null, 'consignment_id' => null,
                'error' => $ok ? null : (string) ($body['message'] ?? 'Carrybee did not accept the bulk request (HTTP ' . $res->status() . ')'),
            ])->values()->all();
        } catch (\Throwable $e) {
            return collect($orders)->map(fn (Order $o) => [
                'invoice' => $o->invoice, 'ok' => false, 'tracking_id' => null, 'consignment_id' => null,
                'error' => __('Could not reach :courier: :detail', ['courier' => 'Carrybee', 'detail' => $e->getMessage()]),
            ])->values()->all();
        }
    }

    /** POST /stores — create a pickup store. The API returns no store id (data: null). */
    public function createStore(CourierMethod $method, array $data): array
    {
        try {
            $res = $this->http($method)->post($this->base($method) . '/api/v2/stores', [
                'name' => $data['name'] ?? null,
                'contact_person_name' => $data['contact_person_name'] ?? null,
                'contact_person_number' => $data['contact_person_number'] ?? null,
                'contact_person_secondary_number' => $data['contact_person_secondary_number'] ?? null,
                'address' => $data['address'] ?? null,
                'city_id' => isset($data['city_id']) ? (int) $data['city_id'] : null,
                'zone_id' => isset($data['zone_id']) ? (int) $data['zone_id'] : null,
                'area_id' => isset($data['area_id']) ? (int) $data['area_id'] : null,
            ]);
            $body = $res->json() ?? [];
            $ok = $res->successful() && ($body['error'] ?? true) === false;

            // The docs example shows data: null, but the live API returns the
            // created store object including its id — keep it when present.
            return ['ok' => $ok, 'store_id' => isset($body['data']['id']) ? (string) $body['data']['id'] : null, 'error' => $ok ? null : (string) ($body['message'] ?? 'Store creation failed (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach :courier: :detail', ['courier' => 'Carrybee', 'detail' => $e->getMessage()])];
        }
    }

    /** GET /cities — every city Carrybee serves (envelope: data.cities). */
    public function getCities(CourierMethod $method): array
    {
        return $this->lookup($method, '/api/v2/cities', 'cities', fn ($r) => [
            'city_id' => $r['id'] ?? null, 'city_name' => (string) ($r['name'] ?? ''),
        ]);
    }

    /** GET /cities/{city_id}/zones — zones inside one city (envelope: data.zones). */
    public function getZones(CourierMethod $method, int $cityId): array
    {
        return $this->lookup($method, '/api/v2/cities/' . $cityId . '/zones', 'zones', fn ($r) => [
            'zone_id' => $r['id'] ?? null, 'zone_name' => (string) ($r['name'] ?? ''), 'city_id' => $r['city_id'] ?? null,
        ]);
    }

    /** GET /cities/{city_id}/zones/{zone_id}/areas — areas inside one zone (envelope: data.areas). */
    public function getAreas(CourierMethod $method, int $cityId, int $zoneId): array
    {
        return $this->lookup($method, '/api/v2/cities/' . $cityId . '/zones/' . $zoneId . '/areas', 'areas', fn ($r) => [
            'area_id' => $r['id'] ?? null, 'area_name' => (string) ($r['name'] ?? ''), 'zone_id' => $r['zone_id'] ?? null,
        ]);
    }

    /** GET /area-suggestion?search= — resolve a place name to city/zone/area ids. */
    public function searchAreas(CourierMethod $method, string $search): array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . '/api/v2/area-suggestion', ['search' => $search]);
            $rows = $res->json('data.items') ?? $res->json('data') ?? [];

            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** POST /address-details {query} — parse a free-text address into city_id + zone_id. */
    public function getAddressDetails(CourierMethod $method, string $address): ?array
    {
        try {
            $res = $this->http($method)->post($this->base($method) . '/api/v2/address-details', ['query' => $address]);
            $data = $res->json('data');

            return $res->successful() && is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function lookup(CourierMethod $method, string $path, string $key, callable $map): array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . $path);
            $rows = $res->json('data.' . $key) ?? $res->json('data.data') ?? $res->json('data') ?? $res->json() ?? [];

            return collect(is_array($rows) ? $rows : [])->map($map)->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * POST /orders/reverse-pickup — book a pickup FROM a customer back to the
     * seller (return pickup). Uses the customer_* field family.
     */
    public function createReversePickup(CourierMethod $method, array $data): array
    {
        try {
            $payload = [
                'store_id' => $data['store_id'] ?? (($method->credentials ?? [])['store_id'] ?? null),
                'merchant_order_id' => $data['merchant_order_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_secondary_phone' => $data['customer_secondary_phone'] ?? null,
                'customer_address' => $data['customer_address'] ?? null,
                'city_id' => isset($data['city_id']) ? (int) $data['city_id'] : null,
                'zone_id' => isset($data['zone_id']) ? (int) $data['zone_id'] : null,
                'area_id' => isset($data['area_id']) ? (int) $data['area_id'] : null,
                'item_weight' => isset($data['item_weight']) ? (float) $data['item_weight'] : null,
                'item_quantity' => isset($data['item_quantity']) ? (int) $data['item_quantity'] : null,
                'product_value' => $data['product_value'] ?? null,
                'product_description' => $data['product_description'] ?? null,
                'special_instruction' => $data['special_instruction'] ?? null,
            ];
            if (isset($data['product_type']) && is_numeric($data['product_type'])) {
                $payload['product_type'] = (int) $data['product_type'];
            }
            $res = $this->http($method)->post($this->base($method) . '/api/v2/orders/reverse-pickup', $payload);
            $body = $res->json() ?? [];
            $d = $body['data'] ?? [];
            $id = $d['consignment_id'] ?? null;
            if ($res->successful() && $id) {
                return ['ok' => true, 'consignment_id' => (string) $id, 'delivery_fee' => $d['delivery_fee'] ?? null, 'raw' => $body];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'Reverse pickup failed (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach :courier: :detail', ['courier' => 'Carrybee', 'detail' => $e->getMessage()])];
        }
    }

    /** POST /orders/{consignment}/exchange — book an exchange delivery against an existing order. */
    public function createExchange(Order $order, CourierMethod $method, array $data = []): array
    {
        try {
            $body = array_filter([
                'merchant_order_id' => $data['merchant_order_id'] ?? null,
                'collectable_amount' => $data['collectable_amount'] ?? null,
                'item_quantity' => isset($data['item_quantity']) ? (int) $data['item_quantity'] : null,
                'item_weight' => isset($data['item_weight']) ? (float) $data['item_weight'] : null,
                'product_description' => $data['product_description'] ?? null,
                'special_instruction' => $data['special_instruction'] ?? null,
            ], fn ($v) => $v !== null);
            $res = $this->http($method)->post($this->base($method) . '/api/v2/orders/' . ($order->consignment_id ?: $order->tracking_id) . '/exchange', $body);
            $resp = $res->json() ?? [];
            $new = $resp['data']['order'] ?? [];
            if ($res->successful() && ! empty($new['consignment_id'])) {
                return ['ok' => true, 'consignment_id' => (string) $new['consignment_id'], 'raw' => $resp];
            }

            return ['ok' => false, 'error' => (string) ($resp['message'] ?? 'Exchange booking failed (HTTP ' . $res->status() . ')'), 'raw' => $resp];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => __('Could not reach :courier: :detail', ['courier' => 'Carrybee', 'detail' => $e->getMessage()])];
        }
    }
}
