<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;
use Illuminate\Support\Facades\Http;

class SteadfastDriver implements CourierDriver
{
    private string $base = 'https://portal.packzy.com/api/v1';

    private function http(CourierMethod $method)
    {
        $c = $method->credentials ?? [];

        return Http::timeout(8)->connectTimeout(4)->withHeaders([
            'Api-Key' => (string) ($c['api_key'] ?? ''),
            'Secret-Key' => (string) ($c['api_secret'] ?? ''),
            'Content-Type' => 'application/json',
        ]);
    }

    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array
    {
        try {
            $res = $this->http($method)->post($this->base . '/create_order', [
                'invoice' => $order->invoice,
                'recipient_name' => $order->customer_name,
                'recipient_phone' => $order->customer_phone,
                'recipient_address' => trim(($order->address ?? '') . ' ' . ($order->area ?? '') . ' ' . ($order->district ?? '')),
                'cod_amount' => $order->codAmount(),
                'note' => $order->delivery_note ?? $method->default_shipping_note,
            ]);
            $body = $res->json() ?? [];
            $consignment = $body['consignment'] ?? $body;
            if ($res->successful() && ! empty($consignment['consignment_id'])) {
                return [
                    'ok' => true,
                    'tracking_id' => (string) ($consignment['tracking_code'] ?? $consignment['consignment_id']),
                    'consignment_id' => (string) $consignment['consignment_id'],
                    'raw' => $body,
                ];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'Steadfast rejected the booking (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach Steadfast: ' . $e->getMessage()];
        }
    }

    public function getStatus(Order $order, CourierMethod $method): array
    {
        try {
            // Consignment ids go to status_by_cid; tracking codes have their own endpoint.
            $res = $order->consignment_id
                ? $this->http($method)->get($this->base . '/status_by_cid/' . $order->consignment_id)
                : $this->http($method)->get($this->base . '/status_by_trackingcode/' . $order->tracking_id);
            $body = $res->json() ?? [];
            if ($res->successful() && isset($body['delivery_status'])) {
                return ['ok' => true, 'courier_status' => (string) $body['delivery_status'], 'raw' => $body];
            }

            return ['ok' => false, 'error' => 'Status check failed (HTTP ' . $res->status() . ')'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach Steadfast: ' . $e->getMessage()];
        }
    }

    public function cancelBooking(Order $order, CourierMethod $method): array
    {
        return ['ok' => true]; // Steadfast has no public cancel endpoint in the documented set.
    }

    public function fraudCheck(string $phone, CourierMethod $method): ?array
    {
        try {
            // Current endpoint: /fraud_check/score/{phone} (the legacy count endpoint is retired).
            // delivery_ratio is the % of FINISHED parcels delivered; null = nothing finished yet,
            // which must read as "no signal", never as a clean record.
            $res = $this->http($method)->get($this->base . '/fraud_check/score/' . $phone);
            $body = $res->json() ?? [];
            if (! $res->successful() || ! array_key_exists('delivery_ratio', $body) || $body['delivery_ratio'] === null) {
                return null;
            }
            $band = (string) ($body['volume_band'] ?? '');
            $reports = (int) ($body['total_reports'] ?? 0);

            return [
                'rate' => (float) $body['delivery_ratio'],
                'delivered' => null,
                'returned' => null,
                'label' => trim(($band !== '' ? ucfirst($band) . ' volume' : '') . ($reports > 0 ? ' · ' . $reports . ' fraud report(s)' : '')) ?: null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function testConnection(CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base . '/get_balance');
            $body = $res->json() ?? [];
            if ($res->successful() && array_key_exists('current_balance', $body)) {
                return ['ok' => true, 'message' => 'Connected. Current balance ৳' . $body['current_balance']];
            }

            return ['ok' => false, 'message' => 'Steadfast did not accept these credentials (HTTP ' . $res->status() . ')'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Steadfast: ' . $e->getMessage()];
        }
    }

    public function syncStores(CourierMethod $method): array
    {
        return [];
    }

    public function getPayments(CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base . '/payments');
            $body = $res->json() ?? [];
            $rows = $body['data'] ?? $body['payments'] ?? $body['items'] ?? [];

            return collect(is_array($rows) ? $rows : [])->map(fn ($p) => [
                'payment_id' => (string) ($p['payment_id'] ?? ''),
                'amount' => isset($p['amount']) ? (float) $p['amount'] : null,
                'method' => $p['method'] ?? null,
                'due_bills' => isset($p['due_bills']) ? (float) $p['due_bills'] : null,
                'paid_bills' => isset($p['paid_bills']) ? (float) $p['paid_bills'] : null,
                'charges' => isset($p['charges']) ? (float) $p['charges'] : null,
                'total' => isset($p['total']) ? (float) $p['total'] : null,
                'status_label' => $p['status_label'] ?? null,
                'paid_at' => $p['paid_at'] ?? null,
                'ready_at' => $p['ready_at'] ?? null,
            ])->filter(fn ($p) => $p['payment_id'] !== '')->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function getPaymentConsignments(CourierMethod $method, string $paymentId): array
    {
        try {
            $res = $this->http($method)->get($this->base . '/payments/' . $paymentId);
            $body = $res->json() ?? [];
            $rows = $body['consignments'] ?? $body['data']['consignments'] ?? $body['bills'] ?? [];

            return collect(is_array($rows) ? $rows : [])->map(function ($c) {
                $amount = null;
                foreach (['amount', 'cod_amount', 'collectable_amount', 'collected_amount'] as $k) {
                    if (isset($c[$k]) && is_numeric($c[$k])) {
                        $amount = (float) $c[$k];
                        break;
                    }
                }

                return ['invoice' => (string) ($c['invoice'] ?? ''), 'amount' => $amount];
            })->filter(fn ($c) => $c['invoice'] !== '')->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function parcelCollection(Order $order, CourierMethod $method): ?array
    {
        return null;
    }
}
