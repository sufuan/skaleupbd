<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;
use Illuminate\Support\Facades\Http;

/**
 * Paperfly — real API, documented inside the merchant panel's Developer
 * Guide (go.paperfly.com.bd/merchant/developer-guide, read 2026-11-17).
 * Base https://api.paperfly.com.bd. Only THREE endpoints exist:
 *  - create/exchange: POST /order_information/new_order_v2.php (key in body)
 *  - tracking:        POST /API-Order-Tracking (Basic Auth = panel login)
 *  - cancel:          POST /order_information/cancel_order.php (form + key)
 * No payments/balance endpoints (Class B — money comes from the tracking
 * response's charge fields + invoice upload). No sandbox: every call is live.
 */
class PaperflyDriver implements CourierDriver
{
    private string $base = 'https://api.paperfly.com.bd';

    private function key(CourierMethod $method): string
    {
        return (string) (($method->credentials ?? [])['api_key'] ?? '');
    }

    private function http(CourierMethod $method)
    {
        return Http::timeout(8)->connectTimeout(4);
    }

    /** The create-order payload, shared by single booking + exchange. */
    private function orderPayload(Order $order, CourierMethod $method): array
    {
        $c = $method->credentials ?? [];
        $cod = (int) round($order->codAmount());

        return [
            'paperflykey' => $this->key($method),
            'merchantReference' => $order->invoice, // must be unique per order
            'receiverName' => $order->customer_name,
            'receiverPhone' => $order->customer_phone,
            'receiverAddress' => trim(($order->address ?? '') . ' ' . ($order->area ?? '') . ' ' . ($order->district ?? '')),
            'receiverDistrict' => $order->district,
            'receiverThana' => $order->area,
            'packagePrice' => (int) round($order->grand_total),
            'productType' => 'Normal',
            'paymentType' => $cod > 0 ? 'COD' : 'Prepaid',
            'deliveryType' => 'Home Delivery',
            'orderWeight' => max(1, (int) ($order->relationLoaded('items') ? $order->items->sum('qty') : 1)),
            'amountToCollect' => $cod,
            // Our committed charge for this connection; Paperfly answers with
            // its own codCharge/totalCharge in the response.
            'deliveryCharge' => (int) round($method->chargeFor($order->effectiveDistrict())),
            'codCharge' => 0,
            'merchantDeliveryInstruction' => $order->delivery_note ?? $method->default_shipping_note,
            'isPartialDelivery' => 0,
            'isFragileProduct' => 0,
            'isExchangeOrder' => 0,
            'pickupStore' => $order->pickupStore?->store_id ?? ($c['pickup_store'] ?? null),
            'orderDate' => optional($order->created_at)->format('Y-m-d'),
        ];
    }

    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array
    {
        try {
            $res = $this->http($method)->post($this->base . '/order_information/new_order_v2.php', $this->orderPayload($order, $method));
            $body = $res->json() ?? [];
            $data = $body['data'] ?? $body;
            $tracking = $data['trackingId'] ?? null;
            if (($body['success'] ?? false) === true && $tracking) {
                return ['ok' => true, 'tracking_id' => (string) $tracking, 'consignment_id' => (string) $tracking, 'raw' => $body];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'Paperfly rejected the booking (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach Paperfly: ' . $e->getMessage()];
        }
    }

    /** Exchange order — same endpoint, flagged, with the returned products listed. */
    public function createExchange(Order $order, CourierMethod $method, array $returnedItems = []): array
    {
        try {
            $payload = $this->orderPayload($order, $method);
            $payload['isExchangeOrder'] = 1;
            $payload['exchangeStatus'] = 'PENDING';
            $payload['exchangeReturnedProducts'] = collect($returnedItems)->map(fn ($i) => [
                'productName' => (string) ($i['productName'] ?? $i['product_name'] ?? ''),
                'productQty' => (string) ($i['productQty'] ?? $i['qty'] ?? '1'),
                'productPrice' => (string) ($i['productPrice'] ?? $i['price'] ?? '0'),
            ])->all();
            $res = $this->http($method)->post($this->base . '/order_information/new_order_v2.php', $payload);
            $body = $res->json() ?? [];
            $data = $body['data'] ?? $body;
            $tracking = $data['trackingId'] ?? $body['trackingId'] ?? null;
            if (($body['success'] ?? false) === true && $tracking) {
                return ['ok' => true, 'tracking_id' => (string) $tracking, 'consignment_id' => (string) $tracking, 'raw' => $body];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'Exchange booking failed (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach Paperfly: ' . $e->getMessage()];
        }
    }

    /** Tracking uses Basic Auth with the merchant PANEL login (not the API key). */
    private function track(Order $order, CourierMethod $method): ?array
    {
        $c = $method->credentials ?? [];
        try {
            $res = $this->http($method)
                ->withBasicAuth((string) ($c['username'] ?? ''), (string) ($c['password'] ?? ''))
                ->post($this->base . '/API-Order-Tracking', ['ReferenceNumber' => $order->invoice]);
            $body = $res->json() ?? [];

            return ($body['success'] ?? false) === true && is_array($body['data'] ?? null) ? $body['data'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getStatus(Order $order, CourierMethod $method): array
    {
        $d = $this->track($order, $method);
        $status = $d['currentStatus'] ?? $d['orderStatus'] ?? null;
        if ($d && ! empty($status)) {
            return ['ok' => true, 'courier_status' => (string) $status, 'raw' => $d];
        }

        return ['ok' => false, 'error' => 'Status check failed'];
    }

    public function cancelBooking(Order $order, CourierMethod $method): array
    {
        try {
            // The cancel endpoint answers response_code 200 + status "success"
            // even when it refuses — only the message tells the truth.
            $res = $this->http($method)->asForm()->post($this->base . '/order_information/cancel_order.php', [
                'paperflykey' => $this->key($method),
                'tracking_id' => $order->tracking_id ?: $order->consignment_id,
            ]);
            $message = (string) (($res->json() ?? [])['message'] ?? '');

            return ['ok' => str_contains(strtolower($message), 'cancelled successfully')];
        } catch (\Throwable) {
            return ['ok' => false];
        }
    }

    public function fraudCheck(string $phone, CourierMethod $method): ?array
    {
        return null; // No fraud/success-rate API.
    }

    public function testConnection(CourierMethod $method): array
    {
        $c = $method->credentials ?? [];
        if (empty($c['username']) || empty($c['password'])) {
            return ['ok' => false, 'message' => 'Add your Paperfly panel Username + Password — the connection test signs in with them'];
        }
        try {
            $res = $this->http($method)
                ->withBasicAuth((string) $c['username'], (string) $c['password'])
                ->post($this->base . '/API-Order-Tracking', ['ReferenceNumber' => 'SKALEUP-CONNECT-TEST']);
            if ($res->status() === 401 || $res->status() === 403) {
                return ['ok' => false, 'message' => 'Paperfly did not accept this panel username + password (HTTP ' . $res->status() . ')'];
            }

            // Any JSON answer (even "order not found") proves the login works.
            return $res->json() !== null
                ? ['ok' => true, 'message' => 'Connected — Paperfly accepted the panel login']
                : ['ok' => false, 'message' => 'Paperfly answered unexpectedly (HTTP ' . $res->status() . ')'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach Paperfly: ' . $e->getMessage()];
        }
    }

    public function syncStores(CourierMethod $method): array
    {
        return []; // No stores endpoint — the pickup store is a name set in Settings / on the order.
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
        $d = $this->track($order, $method);
        if (! $d) {
            return null;
        }
        $delivered = str_contains(strtolower((string) ($d['currentStatus'] ?? '')), 'delivered');

        return [
            'collected' => $delivered && isset($d['amountToCollect']) ? (float) $d['amountToCollect'] : null,
            'cod_fee' => isset($d['codCharge']) ? (float) $d['codCharge'] : null,
            'delivery_fee' => isset($d['totalCharge']) ? (float) $d['totalCharge'] : null,
            'payment_status' => $d['paymentType'] ?? null,
        ];
    }
}
