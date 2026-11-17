<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;
use Illuminate\Support\Facades\Http;

class CarrybeeDriver implements CourierDriver
{
    private string $base = 'https://developers.carrybee.com';

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

    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array
    {
        $c = $method->credentials ?? [];
        try {
            // Field names per the real v2 create-order contract (merchant_order_id / recipient_*).
            $payload = [
                'store_id' => $opts['store_id'] ?? ($order->pickupStore?->store_id ?? ($c['store_id'] ?? null)),
                'merchant_order_id' => $order->invoice,
                'recipient_name' => $order->customer_name,
                'recipient_phone' => $order->customer_phone,
                'recipient_address' => trim(($order->address ?? '') . ' ' . ($order->area ?? '') . ' ' . ($order->district ?? '')),
                'collectable_amount' => $order->codAmount(),
                'item_weight' => (float) ($c['default_weight'] ?? 0.5),
                'special_instruction' => $order->delivery_note ?? $method->default_shipping_note,
            ];
            // product_type / delivery_type are NUMERIC codes in the API — forward only numeric values.
            foreach (['product_type', 'delivery_type'] as $k) {
                if (isset($c[$k]) && is_numeric($c[$k])) {
                    $payload[$k] = (int) $c[$k];
                }
            }
            $res = $this->http($method)->post($this->base . '/api/v2/orders', $payload);
            $body = $res->json() ?? [];
            $data = $body['data'] ?? $body;
            $id = $data['order']['consignment_id'] ?? $data['consignment_id'] ?? $data['id'] ?? $data['order_id'] ?? null;
            if ($res->successful() && $id) {
                return ['ok' => true, 'tracking_id' => (string) $id, 'consignment_id' => (string) $id, 'raw' => $body];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'Carrybee rejected the booking (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach Carrybee: ' . $e->getMessage()];
        }
    }

    private function details(Order $order, CourierMethod $method): ?array
    {
        try {
            $res = $this->http($method)->get($this->base . '/api/v2/orders/' . ($order->consignment_id ?: $order->tracking_id) . '/details');
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

        return ['ok' => false, 'error' => 'Status check failed'];
    }

    public function cancelBooking(Order $order, CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->post($this->base . '/api/v2/orders/' . ($order->consignment_id ?: $order->tracking_id) . '/cancel', [
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
            $res = $this->http($method)->get($this->base . '/api/v2/stores');
            return $res->successful()
                ? ['ok' => true, 'message' => 'Connected — Carrybee accepted the credentials']
                : ['ok' => false, 'message' => 'Carrybee did not accept these credentials (HTTP ' . $res->status() . ')'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Carrybee: ' . $e->getMessage()];
        }
    }

    public function syncStores(CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base . '/api/v2/stores');
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
}
