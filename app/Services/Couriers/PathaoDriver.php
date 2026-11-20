<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PathaoDriver implements CourierDriver
{
    /** Sandbox and production differ ONLY in the host + credentials; paths are identical. */
    private function base(CourierMethod $method): string
    {
        return ! empty(($method->credentials ?? [])['sandbox'])
            ? 'https://courier-api-sandbox.pathao.com'
            : 'https://api-hermes.pathao.com';
    }

    private function token(CourierMethod $method): ?string
    {
        $c = $method->credentials ?? [];
        $key = 'pathao_token_' . $method->id;
        if ($cached = Cache::get($key)) {
            return $cached;
        }
        // Prefer the refresh grant when a refresh token is on hand; fall back to the password grant.
        $refreshKey = 'pathao_refresh_' . $method->id;
        if ($refresh = Cache::get($refreshKey)) {
            $token = $this->issueToken($method, ['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
            if ($token) {
                return $token;
            }
            Cache::forget($refreshKey);
        }

        return $this->issueToken($method, [
            'username' => (string) ($c['client_email'] ?? ''),
            'password' => (string) ($c['password'] ?? ''),
            'grant_type' => 'password',
        ]);
    }

    private function issueToken(CourierMethod $method, array $grant): ?string
    {
        $c = $method->credentials ?? [];
        try {
            $res = Http::timeout(30)->connectTimeout(8)->post($this->base($method) . '/aladdin/api/v1/issue-token', $grant + [
                'client_id' => (string) ($c['client_id'] ?? ''),
                'client_secret' => (string) ($c['client_secret'] ?? ''),
            ]);
            $token = $res->json('access_token');
            if ($res->successful() && $token) {
                $ttl = max(60, (int) ($res->json('expires_in') ?? 3000) - 300);
                Cache::put('pathao_token_' . $method->id, $token, now()->addSeconds($ttl));
                if ($refresh = $res->json('refresh_token')) {
                    Cache::put('pathao_refresh_' . $method->id, $refresh, now()->addDays(30));
                }

                return $token;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function http(CourierMethod $method)
    {
        return Http::timeout(30)->connectTimeout(8)->withToken((string) $this->token($method));
    }

    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array
    {
        $c = $method->credentials ?? [];
        $storeId = $opts['store_id'] ?? ($order->pickupStore?->store_id ?? ($c['store_id'] ?? null));
        if (! $storeId) {
            return ['ok' => false, 'error' => 'Pathao needs a pickup store — sync stores in Settings and pick one'];
        }
        if (! $this->token($method)) {
            return ['ok' => false, 'error' => 'Pathao login failed — check the credentials in Settings'];
        }
        try {
            // City/zone/area are OPTIONAL in Pathao's API — when not configured, Pathao
            // populates them from the recipient address. Send them only when set; never send 0.
            $payload = [
                'store_id' => (int) $storeId,
                'merchant_order_id' => $order->invoice,
                'recipient_name' => $order->customer_name,
                'recipient_phone' => $order->customer_phone,
                'recipient_address' => trim(($order->address ?? '') . ' ' . ($order->area ?? '') . ' ' . ($order->district ?? '')),
                'delivery_type' => 48,
                'item_type' => 2,
                'special_instruction' => $order->delivery_note ?? $method->default_shipping_note,
                'item_quantity' => max(1, (int) $order->items->sum('qty')),
                'item_weight' => 0.5,
                'amount_to_collect' => $order->codAmount(),
            ];
            if (! empty($order->alt_phone)) {
                $payload['recipient_secondary_phone'] = $order->alt_phone;
            }
            if (! empty($c['default_city_id'])) {
                $payload['recipient_city'] = (int) $c['default_city_id'];
            }
            if (! empty($c['default_zone_id'])) {
                $payload['recipient_zone'] = (int) $c['default_zone_id'];
            }
            if (! empty($c['default_area_id'])) {
                $payload['recipient_area'] = (int) $c['default_area_id'];
            }
            $res = $this->http($method)->post($this->base($method) . '/aladdin/api/v1/orders', $payload);
            $body = $res->json() ?? [];
            $data = $body['data'] ?? [];
            if ($res->successful() && ! empty($data['consignment_id'])) {
                return ['ok' => true, 'tracking_id' => (string) $data['consignment_id'], 'consignment_id' => (string) $data['consignment_id'], 'raw' => $body];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'Pathao rejected the booking (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach Pathao: ' . $e->getMessage()];
        }
    }

    public function getStatus(Order $order, CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . '/aladdin/api/v1/orders/' . ($order->consignment_id ?: $order->tracking_id) . '/info');
            $body = $res->json() ?? [];
            $status = $body['data']['order_status_slug'] ?? $body['data']['order_status'] ?? $body['order_status_slug'] ?? $body['order_status'] ?? null;
            if ($res->successful() && $status) {
                return ['ok' => true, 'courier_status' => (string) $status, 'raw' => $body];
            }

            return ['ok' => false, 'error' => 'Status check failed (HTTP ' . $res->status() . ')'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach Pathao: ' . $e->getMessage()];
        }
    }

    public function cancelBooking(Order $order, CourierMethod $method): array
    {
        return ['ok' => true];
    }

    public function fraudCheck(string $phone, CourierMethod $method): ?array
    {
        try {
            $res = $this->http($method)->post($this->base($method) . '/aladdin/api/v1/user/success-rate', ['phone' => $phone]);
            $data = $res->json('data') ?? [];
            if (! $res->successful() || empty($data)) {
                return null;
            }
            if (isset($data['success_rate']) && is_numeric($data['success_rate'])) {
                return ['rate' => (float) $data['success_rate'], 'delivered' => null, 'returned' => null, 'label' => null];
            }
            $rating = strtolower((string) ($data['rating'] ?? $data['customer_rating'] ?? ''));
            $rate = match (true) {
                str_contains($rating, 'excellent') => 95.0,
                str_contains($rating, 'good') => 80.0,
                str_contains($rating, 'moderate') => 60.0,
                str_contains($rating, 'risky') => 30.0,
                default => null,
            };

            return $rate === null ? null : ['rate' => $rate, 'delivered' => null, 'returned' => null, 'label' => ucfirst($rating)];
        } catch (\Throwable) {
            return null;
        }
    }

    public function testConnection(CourierMethod $method): array
    {
        return $this->token($method)
            ? ['ok' => true, 'message' => 'Connected — Pathao accepted the login']
            : ['ok' => false, 'message' => 'Pathao login failed — check Client Id, Secret, Email and Password'];
    }

    public function syncStores(CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . '/aladdin/api/v1/stores');
            $rows = $res->json('data.data') ?? $res->json('data') ?? [];

            return collect(is_array($rows) ? $rows : [])->map(fn ($s) => [
                'store_id' => (string) ($s['store_id'] ?? $s['id'] ?? ''),
                'name' => (string) ($s['store_name'] ?? $s['name'] ?? ''),
            ])->filter(fn ($s) => $s['store_id'] !== '')->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function getPayments(CourierMethod $method): array
    {
        return []; // No settlement API (Class B) — statement upload / one-line entry.
    }

    public function getPaymentConsignments(CourierMethod $method, string $paymentId): array
    {
        return [];
    }

    public function parcelCollection(Order $order, CourierMethod $method): ?array
    {
        return null;
    }

    // ---- The rest of Pathao's published API surface (panel API Documentation list) ----

    /** POST /stores — create a pickup store. Pathao approves new stores (~1 hour). */
    public function createStore(CourierMethod $method, array $data): array
    {
        try {
            $res = $this->http($method)->post($this->base($method) . '/aladdin/api/v1/stores', [
                'name' => $data['name'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
                'secondary_contact' => $data['secondary_contact'] ?? null,
                'address' => $data['address'] ?? null,
                'city_id' => isset($data['city_id']) ? (int) $data['city_id'] : null,
                'zone_id' => isset($data['zone_id']) ? (int) $data['zone_id'] : null,
                'area_id' => isset($data['area_id']) ? (int) $data['area_id'] : null,
            ]);
            $body = $res->json() ?? [];
            if ($res->successful()) {
                return ['ok' => true, 'store' => $body['data'] ?? $body, 'raw' => $body];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'Store creation failed (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach Pathao: ' . $e->getMessage()];
        }
    }

    /**
     * POST /orders/bulk — many parcels in one call. Pathao answers 202 Accepted with
     * no per-order breakdown, so every order is reported as accepted (not confirmed);
     * the per-order status shows up later via order info / webhooks.
     */
    public function createBulkBookings(array $orders, CourierMethod $method, array $opts = []): array
    {
        $c = $method->credentials ?? [];
        $storeId = $opts['store_id'] ?? ($c['store_id'] ?? null);
        $payloads = collect($orders)->map(function (Order $o) use ($method, $storeId, $c) {
            $sid = $o->pickupStore?->store_id ?? $storeId;
            $p = [
                'store_id' => $sid ? (int) $sid : null,
                'merchant_order_id' => $o->invoice,
                'recipient_name' => $o->customer_name,
                'recipient_phone' => $o->customer_phone,
                'recipient_address' => trim(($o->address ?? '') . ' ' . ($o->area ?? '') . ' ' . ($o->district ?? '')),
                'delivery_type' => 48,
                'item_type' => 2,
                'special_instruction' => $o->delivery_note ?? $method->default_shipping_note,
                'item_quantity' => max(1, (int) ($o->relationLoaded('items') ? $o->items->sum('qty') : 1)),
                'item_weight' => 0.5,
                'amount_to_collect' => $o->codAmount(),
            ];
            if (! empty($o->alt_phone)) {
                $p['recipient_secondary_phone'] = $o->alt_phone;
            }
            if (! empty($c['default_city_id'])) {
                $p['recipient_city'] = (int) $c['default_city_id'];
            }
            if (! empty($c['default_zone_id'])) {
                $p['recipient_zone'] = (int) $c['default_zone_id'];
            }
            if (! empty($c['default_area_id'])) {
                $p['recipient_area'] = (int) $c['default_area_id'];
            }

            return $p;
        })->all();
        try {
            $res = $this->http($method)->post($this->base($method) . '/aladdin/api/v1/orders/bulk', ['orders' => $payloads]);
            $ok = $res->successful();

            return collect($orders)->map(fn (Order $o) => [
                'invoice' => $o->invoice, 'ok' => $ok, 'tracking_id' => null, 'consignment_id' => null,
                'error' => $ok ? null : 'Pathao did not accept the bulk request (HTTP ' . $res->status() . ')',
            ])->values()->all();
        } catch (\Throwable $e) {
            return collect($orders)->map(fn (Order $o) => [
                'invoice' => $o->invoice, 'ok' => false, 'tracking_id' => null, 'consignment_id' => null,
                'error' => 'Could not reach Pathao: ' . $e->getMessage(),
            ])->values()->all();
        }
    }

    /** GET /city-list — every city Pathao serves. */
    public function getCities(CourierMethod $method): array
    {
        return $this->lookup($method, '/aladdin/api/v1/city-list', fn ($r) => [
            'city_id' => $r['city_id'] ?? null, 'city_name' => (string) ($r['city_name'] ?? ''),
        ]);
    }

    /** GET /cities/{city_id}/zone-list — zones inside one city. */
    public function getZones(CourierMethod $method, int $cityId): array
    {
        return $this->lookup($method, '/aladdin/api/v1/cities/' . $cityId . '/zone-list', fn ($r) => [
            'zone_id' => $r['zone_id'] ?? null, 'zone_name' => (string) ($r['zone_name'] ?? ''),
        ]);
    }

    /** GET /zones/{zone_id}/area-list — areas inside one zone. */
    public function getAreas(CourierMethod $method, int $zoneId): array
    {
        return $this->lookup($method, '/aladdin/api/v1/zones/' . $zoneId . '/area-list', fn ($r) => [
            'area_id' => $r['area_id'] ?? null, 'area_name' => (string) ($r['area_name'] ?? ''),
            'home_delivery_available' => $r['home_delivery_available'] ?? null,
        ]);
    }

    private function lookup(CourierMethod $method, string $path, callable $map): array
    {
        try {
            $res = $this->http($method)->get($this->base($method) . $path);
            $rows = $res->json('data.data') ?? $res->json('data') ?? [];

            return collect(is_array($rows) ? $rows : [])->map($map)->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** POST /merchant/price-plan — the delivery price for one parcel shape, before booking. */
    public function pricePlan(CourierMethod $method, array $params): ?array
    {
        try {
            $res = $this->http($method)->post($this->base($method) . '/aladdin/api/v1/merchant/price-plan', [
                'store_id' => isset($params['store_id']) ? (int) $params['store_id'] : null,
                'item_type' => (int) ($params['item_type'] ?? 2),
                'delivery_type' => (int) ($params['delivery_type'] ?? 48),
                'item_weight' => (float) ($params['item_weight'] ?? 0.5),
                'recipient_city' => isset($params['recipient_city']) ? (int) $params['recipient_city'] : null,
                'recipient_zone' => isset($params['recipient_zone']) ? (int) $params['recipient_zone'] : null,
            ]);
            $data = $res->json('data');

            return $res->successful() && is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
