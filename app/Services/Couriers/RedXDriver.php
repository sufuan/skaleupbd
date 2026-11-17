<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;
use Illuminate\Support\Facades\Http;

class RedXDriver implements CourierDriver
{
    private string $base = 'https://openapi.redx.com.bd/v1.0.0-beta';

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
        if (empty($c['default_area_id']) && empty($c['default_area'])) {
            return ['ok' => false, 'error' => 'RedX needs a delivery area — set the default Area ID in Settings → Courier → RedX'];
        }
        try {
            $payload = [
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'customer_address' => trim(($order->address ?? '') . ' ' . ($order->area ?? '') . ' ' . ($order->district ?? '')),
                'delivery_area' => (string) ($c['default_area'] ?? ''),
                'delivery_area_id' => (int) ($c['default_area_id'] ?? 0),
                'merchant_invoice_id' => $order->invoice,
                'parcel_weight' => 500,
                'cash_collection_amount' => $order->codAmount(),
                'value' => $order->codAmount(),
                'parcel_details_json' => $order->items->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->qty])->values()->all(),
            ];
            // The Settings "Pickup Store" field holds the RedX pickup store ID.
            if (! empty($c['pickup_store']) && is_numeric($c['pickup_store'])) {
                $payload['pickup_store_id'] = (int) $c['pickup_store'];
            }
            $res = $this->http($method)->post($this->base . '/parcel', $payload);
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

    public function getStatus(Order $order, CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base . '/parcel/track/' . ($order->tracking_id ?: $order->consignment_id));
            $body = $res->json() ?? [];
            $status = $body['tracking']['present_status'] ?? $body['data']['status'] ?? $body['status'] ?? null;
            if ($res->successful() && $status) {
                return ['ok' => true, 'courier_status' => (string) $status, 'raw' => $body];
            }

            return ['ok' => false, 'error' => 'Status check failed (HTTP ' . $res->status() . ')'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach RedX: ' . $e->getMessage()];
        }
    }

    public function cancelBooking(Order $order, CourierMethod $method): array
    {
        return ['ok' => true];
    }

    public function fraudCheck(string $phone, CourierMethod $method): ?array
    {
        return null; // No official public fraud-check API (research 2026-11-15).
    }

    public function testConnection(CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base . '/areas');
            return $res->successful()
                ? ['ok' => true, 'message' => 'Connected — RedX accepted the access token']
                : ['ok' => false, 'message' => 'RedX did not accept the access token (HTTP ' . $res->status() . ')'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach RedX: ' . $e->getMessage()];
        }
    }

    public function syncStores(CourierMethod $method): array
    {
        return []; // Pickup store is a Settings text field for RedX (§10.7a).
    }

    public function getPayments(CourierMethod $method): array
    {
        return [];
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
