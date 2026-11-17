<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;
use Illuminate\Support\Facades\Http;

class ECourierDriver implements CourierDriver
{
    private string $base = 'https://backoffice.ecourier.com.bd/api';

    private function http(CourierMethod $method)
    {
        $c = $method->credentials ?? [];

        return Http::timeout(8)->connectTimeout(4)->withHeaders([
            'API-KEY' => (string) ($c['api_key'] ?? ''),
            'API-SECRET' => (string) ($c['api_secret'] ?? ''),
            'USER-ID' => (string) ($c['user_id'] ?? ''),
            'Content-Type' => 'application/json',
        ]);
    }

    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array
    {
        $c = $method->credentials ?? [];
        try {
            $res = $this->http($method)->post($this->base . '/order-place', [
                'name' => $order->customer_name,
                'phone' => $order->customer_phone,
                'address' => trim(($order->address ?? '') . ' ' . ($order->area ?? '') . ' ' . ($order->district ?? '')),
                'package_code' => $c['package_code'] ?? '#2505',
                'product_price' => $order->codAmount(),
                'payment_method' => 'COD',
                'hub_id' => $c['pickup_hub_id'] ?? null,
                'invoice' => $order->invoice,
            ]);
            $body = $res->json() ?? [];
            $tracking = $body['tracking'] ?? $body['data']['tracking'] ?? null;
            if ($res->successful() && $tracking) {
                return ['ok' => true, 'tracking_id' => (string) $tracking, 'consignment_id' => (string) $tracking, 'raw' => $body];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'eCourier rejected the booking (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach eCourier: ' . $e->getMessage()];
        }
    }

    public function getStatus(Order $order, CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->post($this->base . '/track', ['tracking' => $order->tracking_id ?: $order->consignment_id]);
            $body = $res->json() ?? [];
            $status = $body['status'] ?? $body['data']['status'] ?? null;
            if ($res->successful() && $status) {
                return ['ok' => true, 'courier_status' => (string) $status, 'raw' => $body];
            }

            return ['ok' => false, 'error' => 'Status check failed (HTTP ' . $res->status() . ')'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach eCourier: ' . $e->getMessage()];
        }
    }

    public function cancelBooking(Order $order, CourierMethod $method): array
    {
        return ['ok' => true];
    }

    public function fraudCheck(string $phone, CourierMethod $method): ?array
    {
        try {
            $res = $this->http($method)->post($this->base . '/fraud-status-check', ['phone' => $phone]);
            $body = $res->json() ?? [];
            $label = $body['status'] ?? $body['data']['status'] ?? null;
            if ($res->successful() && $label) {
                return ['rate' => null, 'delivered' => null, 'returned' => null, 'label' => (string) $label];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public function testConnection(CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->post($this->base . '/payment-status', ['tracking' => 'TEST']);
            return $res->status() < 500
                ? ['ok' => true, 'message' => 'Connected — eCourier answered']
                : ['ok' => false, 'message' => 'eCourier did not answer properly (HTTP ' . $res->status() . ')'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach eCourier: ' . $e->getMessage()];
        }
    }

    public function syncStores(CourierMethod $method): array
    {
        return [];
    }

    public function getPayments(CourierMethod $method): array
    {
        return []; // Partial only: per-parcel payment status.
    }

    public function getPaymentConsignments(CourierMethod $method, string $paymentId): array
    {
        return [];
    }

    public function parcelCollection(Order $order, CourierMethod $method): ?array
    {
        try {
            $res = $this->http($method)->post($this->base . '/payment-status', ['tracking' => $order->tracking_id ?: $order->consignment_id]);
            $body = $res->json() ?? [];
            if (! $res->successful()) {
                return null;
            }

            return [
                'collected' => isset($body['collected_amount']) ? (float) $body['collected_amount'] : null,
                'cod_fee' => null,
                'delivery_fee' => isset($body['shipping_charge']) ? (float) $body['shipping_charge'] : null,
                'payment_status' => isset($body['payment_id']) ? 'Paid ' . $body['payment_id'] : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
