<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PathaoDriver implements CourierDriver
{
    private string $base = 'https://api-hermes.pathao.com';

    private function token(CourierMethod $method): ?string
    {
        $c = $method->credentials ?? [];
        $key = 'pathao_token_' . $method->id;
        if ($cached = Cache::get($key)) {
            return $cached;
        }
        try {
            $res = Http::timeout(8)->connectTimeout(4)->post($this->base . '/aladdin/api/v1/issue-token', [
                'client_id' => (string) ($c['client_id'] ?? ''),
                'client_secret' => (string) ($c['client_secret'] ?? ''),
                'username' => (string) ($c['client_email'] ?? ''),
                'password' => (string) ($c['password'] ?? ''),
                'grant_type' => 'password',
            ]);
            $token = $res->json('access_token');
            if ($res->successful() && $token) {
                Cache::put($key, $token, now()->addMinutes(50));

                return $token;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function http(CourierMethod $method)
    {
        return Http::timeout(8)->connectTimeout(4)->withToken((string) $this->token($method));
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
            if (! empty($c['default_city_id'])) {
                $payload['recipient_city'] = (int) $c['default_city_id'];
            }
            if (! empty($c['default_zone_id'])) {
                $payload['recipient_zone'] = (int) $c['default_zone_id'];
            }
            if (! empty($c['default_area_id'])) {
                $payload['recipient_area'] = (int) $c['default_area_id'];
            }
            $res = $this->http($method)->post($this->base . '/aladdin/api/v1/orders', $payload);
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
            $res = $this->http($method)->get($this->base . '/aladdin/api/v1/orders/' . ($order->consignment_id ?: $order->tracking_id) . '/info');
            $body = $res->json() ?? [];
            $status = $body['data']['order_status'] ?? null;
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
            $res = $this->http($method)->post($this->base . '/aladdin/api/v1/user/success-rate', ['phone' => $phone]);
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
            $res = $this->http($method)->get($this->base . '/aladdin/api/v1/stores');
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
}
