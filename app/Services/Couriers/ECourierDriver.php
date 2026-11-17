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
            // Field names + mandatory geography per the official API Document v5.5 (§2.1).
            // Orders carry district + area only, so thana/area fall back to the district —
            // best effort until orders capture thana/zip directly.
            $payload = [
                'recipient_name' => $order->customer_name,
                'recipient_mobile' => $order->customer_phone,
                'recipient_city' => (string) ($order->district ?? ''),
                'recipient_thana' => (string) ($order->area ?: $order->district ?: ''),
                'recipient_area' => (string) ($order->area ?: $order->district ?: ''),
                'recipient_address' => trim(($order->address ?? '') . ' ' . ($order->area ?? '') . ' ' . ($order->district ?? '')),
                'recipient_zip' => '',
                'package_code' => $c['package_code'] ?? '#2505',
                'product_price' => $order->codAmount(),
                'payment_method' => 'COD',
                'product_id' => $order->invoice,
            ];
            if (! empty($c['pickup_hub_id'])) {
                $payload['pick_hub'] = $c['pickup_hub_id'];
            }
            $res = $this->http($method)->post($this->base . '/order-place', $payload);
            $body = $res->json() ?? [];
            $tracking = $body['ID'] ?? $body['tracking'] ?? $body['data']['tracking'] ?? null; // success returns the ID field
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
            $res = $this->http($method)->post($this->base . '/track', ['ecr' => $order->tracking_id ?: $order->consignment_id]);
            $body = $res->json() ?? [];
            // Response: query_data[0].status is the history list, newest first — [0][0] is the current status text.
            $status = $body['query_data'][0]['status'][0][0] ?? $body['status'] ?? $body['data']['status'] ?? null;
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
        try {
            $res = $this->http($method)->post($this->base . '/cancel-order', [
                'tracking' => $order->tracking_id ?: $order->consignment_id,
                'comment' => 'Cancelled by the seller',
            ]);

            return ['ok' => $res->successful()];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    public function fraudCheck(string $phone, CourierMethod $method): ?array
    {
        try {
            // v5.5 §2.5: body is {number}; the verdict label lives in customer_status.
            // (The service itself must be enabled via eCourier support; label-only by design.)
            $res = $this->http($method)->post($this->base . '/fraud-status-check', ['number' => $phone]);
            $body = $res->json() ?? [];
            $label = $body['customer_status'] ?? $body['status'] ?? $body['data']['status'] ?? null;
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
            // Wrong credentials come back 401 — anything short of a 2xx must NOT read as connected.
            return $res->successful()
                ? ['ok' => true, 'message' => 'Connected — eCourier answered']
                : ['ok' => false, 'message' => 'eCourier did not accept these credentials (HTTP ' . $res->status() . ')'];
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

            $m = $body['message'] ?? $body; // v5.5 §2.18: everything is nested under "message"

            return [
                'collected' => isset($m['collected_amount']) ? (float) $m['collected_amount'] : null,
                'cod_fee' => null,
                'delivery_fee' => isset($m['shipping_charge']) ? (float) $m['shipping_charge'] : null,
                'payment_status' => isset($m['payment_id']) ? 'Paid ' . $m['payment_id'] : null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
