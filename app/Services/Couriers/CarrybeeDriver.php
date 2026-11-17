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
            $res = $this->http($method)->post($this->base . '/api/v2/orders', [
                'store_id' => $opts['store_id'] ?? ($c['store_id'] ?? null),
                'invoice' => $order->invoice,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'customer_address' => trim(($order->address ?? '') . ' ' . ($order->area ?? '') . ' ' . ($order->district ?? '')),
                'collectable_amount' => $order->codAmount(),
                'product_type' => $c['product_type'] ?? 'Parcel',
                'delivery_type' => $c['delivery_type'] ?? 'Normal',
                'note' => $order->delivery_note ?? $method->default_shipping_note,
            ]);
            $body = $res->json() ?? [];
            $data = $body['data'] ?? $body;
            $id = $data['consignment_id'] ?? $data['id'] ?? $data['order_id'] ?? null;
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
        if ($d && ! empty($d['status'])) {
            return ['ok' => true, 'courier_status' => (string) $d['status'], 'raw' => $d];
        }

        return ['ok' => false, 'error' => 'Status check failed'];
    }

    public function cancelBooking(Order $order, CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->post($this->base . '/api/v2/orders/' . ($order->consignment_id ?: $order->tracking_id) . '/cancel');

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
            $rows = $res->json('data') ?? [];

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
