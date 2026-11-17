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

    /** The order fields Steadfast accepts, shared by single and bulk booking. */
    private function orderPayload(Order $order, CourierMethod $method): array
    {
        $payload = [
            'invoice' => $order->invoice,
            'recipient_name' => $order->customer_name,
            'recipient_phone' => $order->customer_phone,
            'recipient_address' => trim(($order->address ?? '') . ' ' . ($order->area ?? '') . ' ' . ($order->district ?? '')),
            'cod_amount' => $order->codAmount(),
            'note' => $order->delivery_note ?? $method->default_shipping_note,
        ];
        if (! empty($order->alt_phone)) {
            $payload['alternative_phone'] = $order->alt_phone;
        }
        $email = $order->customer_email ?? $order->email ?? null;
        if (! empty($email)) {
            $payload['recipient_email'] = $email;
        }
        if ($order->relationLoaded('items') && $order->items->isNotEmpty()) {
            $payload['total_lot'] = (int) $order->items->sum('qty');
            $names = $order->items->map(fn ($i) => $i->product_name ?? $i->name ?? null)->filter()->implode(', ');
            if ($names !== '') {
                $payload['item_description'] = mb_substr($names, 0, 255);
            }
        }

        return $payload;
    }

    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array
    {
        try {
            $res = $this->http($method)->post($this->base . '/create_order', $this->orderPayload($order, $method));
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
            // The return-status variant answers the same delivery status, and is the only
            // endpoint that says a returning parcel is actually back with the seller.
            $res = $order->consignment_id
                ? $this->http($method)->get($this->base . '/status_with_return_status_by_cid/' . $order->consignment_id)
                : ($order->tracking_id
                    ? $this->http($method)->get($this->base . '/status_by_trackingcode/' . $order->tracking_id)
                    : $this->http($method)->get($this->base . '/status_by_invoice/' . $order->invoice));
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
        // No plain cancel endpoint exists; the documented way to undo a booking is a
        // return request — Steadfast brings the parcel back before delivery (answers 201).
        try {
            $res = $this->http($method)->post($this->base . '/create_return_request', [
                'consignment_id' => $order->consignment_id ? (int) $order->consignment_id : null,
                'invoice' => $order->invoice,
                'reason' => 'Cancelled by the seller',
            ]);
            $body = $res->json() ?? [];
            if ($res->successful()) {
                return ['ok' => true, 'raw' => $body];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'Steadfast refused the return request (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach Steadfast: ' . $e->getMessage()];
        }
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
                // Exact counts ride along only for regularly-booking merchants; null = not shown.
                'delivered' => isset($body['delivered_count']) ? (int) $body['delivered_count'] : null,
                'returned' => isset($body['cancelled_count']) ? (int) $body['cancelled_count'] : null,
                'label' => trim(($band !== '' ? ucfirst($band) . ' volume' : '') . ($reports > 0 ? ' · ' . $reports . ' fraud report(s)' : '')) ?: null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    public function testConnection(CourierMethod $method): array
    {
        // Ping first (no keys needed): if it fails, the problem is the connection,
        // not the credentials — the two failures must not read the same.
        $ping = $this->ping();
        if (! $ping['ok']) {
            return ['ok' => false, 'message' => 'Could not reach Steadfast at all — check the server connection'];
        }
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
            // Payouts come ten to a page, newest first — walk the pages (capped) so older
            // payouts are not silently missed.
            $rows = [];
            for ($page = 1; $page <= 20; $page++) {
                $res = $this->http($method)->get($this->base . '/payments', ['sort' => 'desc', 'page' => $page]);
                $body = $res->json() ?? [];
                $batch = $body['data'] ?? $body['payments'] ?? $body['items'] ?? [];
                if (! is_array($batch) || $batch === []) {
                    break;
                }
                $rows = array_merge($rows, $batch);
                if (count($batch) < 10) {
                    break;
                }
            }

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

    // ---- The rest of Steadfast's published API surface (panel API guide, 21 endpoints) ----

    /** GET /ping — the one call that needs no credentials; a pure connectivity check. */
    public function ping(): array
    {
        try {
            $res = Http::timeout(8)->connectTimeout(4)->get($this->base . '/ping');
            $body = $res->json() ?? [];

            return ['ok' => $res->successful() && ($body['message'] ?? null) === 'pong', 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * POST /create_order/bulk-order (or /extended) — up to 500 parcels in one call.
     * The whole request answers 200 even when some parcels fail; every input order
     * gets one result row back, matched by invoice. Extended returns human-readable
     * error sentences instead of codes and is the default for new integrations.
     */
    public function createBulkBookings(array $orders, CourierMethod $method, bool $extended = true): array
    {
        try {
            $data = collect($orders)->map(fn (Order $o) => $this->orderPayload($o, $method))->all();
            $res = $this->http($method)->post($this->base . '/create_order/bulk-order' . ($extended ? '/extended' : ''), ['data' => $data]);
            $body = $res->json() ?? [];
            $rows = $body['data'] ?? [];

            return collect(is_array($rows) ? $rows : [])->map(function ($r) {
                $ok = ($r['status'] ?? null) === 'success' && ! empty($r['consignment_id']);
                $error = $r['error'] ?? null;

                return [
                    'invoice' => (string) ($r['invoice'] ?? ''),
                    'ok' => $ok,
                    'tracking_id' => $ok ? (string) ($r['tracking_code'] ?? $r['consignment_id']) : null,
                    'consignment_id' => $ok ? (string) $r['consignment_id'] : null,
                    'error' => $ok ? null : (is_array($error) ? implode(' ', $error) : (string) ($error ?? 'Booking failed')),
                ];
            })->values()->all();
        } catch (\Throwable $e) {
            return collect($orders)->map(fn (Order $o) => [
                'invoice' => $o->invoice, 'ok' => false, 'tracking_id' => null, 'consignment_id' => null,
                'error' => 'Could not reach Steadfast: ' . $e->getMessage(),
            ])->values()->all();
        }
    }

    /** GET /status_by_cid/{id} — plain delivery status by consignment id. */
    public function getStatusByCid(string $consignmentId, CourierMethod $method): ?string
    {
        return $this->statusFrom('/status_by_cid/' . $consignmentId, $method);
    }

    /** GET /status_by_invoice/{invoice} — plain delivery status by our own order number. */
    public function getStatusByInvoice(string $invoice, CourierMethod $method): ?string
    {
        return $this->statusFrom('/status_by_invoice/' . $invoice, $method);
    }

    private function statusFrom(string $path, CourierMethod $method): ?string
    {
        try {
            $res = $this->http($method)->get($this->base . $path);
            $body = $res->json() ?? [];

            return $res->successful() && isset($body['delivery_status']) ? (string) $body['delivery_status'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** GET /trackings_by_invoice/{invoice} — every step the parcel has been through. */
    public function getTrackingHistory(string $invoice, CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base . '/trackings_by_invoice/' . $invoice);
            $body = $res->json() ?? [];
            $rows = $body['tracking'] ?? [];

            return collect(is_array($rows) ? $rows : [])->map(fn ($t) => [
                'text' => (string) ($t['text'] ?? ''),
                'created_at' => $t['created_at'] ?? null,
                'tracking_type' => isset($t['tracking_type']) ? (int) $t['tracking_type'] : null,
            ])->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** GET /parcel_states — parcels + COD riding on each dashboard state. */
    public function getParcelStates(CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base . '/parcel_states');
            $body = $res->json() ?? [];

            return is_array($body['states'] ?? null) ? $body['states'] : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** GET /parcel_states/{state} — the parcels in one state, a page at a time. */
    public function getParcelsByState(string $state, CourierMethod $method, int $page = 1, int $perPage = 20): array
    {
        try {
            $res = $this->http($method)->get($this->base . '/parcel_states/' . $state, ['page' => $page, 'per_page' => $perPage, 'sort' => 'desc']);
            $body = $res->json() ?? [];

            return ['data' => is_array($body['data'] ?? null) ? $body['data'] : [], 'meta' => $body['meta'] ?? []];
        } catch (\Throwable) {
            return ['data' => [], 'meta' => []];
        }
    }

    /** GET /stats — counts + COD per outcome for parcels booked between two dates. */
    public function getStats(CourierMethod $method, ?string $from = null, ?string $to = null): array
    {
        try {
            $query = array_filter(['from' => $from, 'to' => $to]);
            $res = $this->http($method)->get($this->base . '/stats', $query);

            return $res->json() ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** POST /create_pickup_request — ask a rider to collect from a saved pickup address. */
    public function createPickupRequest(CourierMethod $method, array $data): array
    {
        try {
            $res = $this->http($method)->post($this->base . '/create_pickup_request', [
                'address_id' => $data['address_id'] ?? null,
                'police_station_id' => $data['police_station_id'] ?? null,
                'address' => $data['address'] ?? null,
                'contact_number' => $data['contact_number'] ?? null,
                'note' => $data['note'] ?? null,
                'estim_qty' => $data['estim_qty'] ?? null,
            ]);
            $body = $res->json() ?? [];
            if ($res->successful()) {
                return ['ok' => true, 'id' => $body['data']['id'] ?? null, 'raw' => $body];
            }

            return ['ok' => false, 'error' => (string) ($body['message'] ?? 'Pickup request failed (HTTP ' . $res->status() . ')'), 'raw' => $body];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach Steadfast: ' . $e->getMessage()];
        }
    }

    /** GET /get_return_requests — our return requests, newest first, ten to a page. */
    public function getReturnRequests(CourierMethod $method, int $page = 1): array
    {
        try {
            $res = $this->http($method)->get($this->base . '/get_return_requests', ['page' => $page]);
            $body = $res->json() ?? [];
            $rows = $body['data'] ?? $body['return_requests'] ?? [];

            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** GET /get_return_request/{id} — one return request with the parcel it is about. */
    public function getReturnRequest(string $id, CourierMethod $method): ?array
    {
        try {
            $res = $this->http($method)->get($this->base . '/get_return_request/' . $id);
            $body = $res->json() ?? [];

            return $res->successful() && $body !== [] ? $body : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** GET /police_stations — every thana Steadfast delivers to, with its district. Changes rarely; callers may cache. */
    public function getPoliceStations(CourierMethod $method): array
    {
        try {
            $res = $this->http($method)->get($this->base . '/police_stations');
            $body = $res->json() ?? [];
            $rows = $body['data'] ?? $body['police_stations'] ?? (array_is_list($body) ? $body : []);

            return collect(is_array($rows) ? $rows : [])->map(fn ($s) => [
                'id' => $s['id'] ?? null,
                'name' => (string) ($s['name'] ?? $s['police_station'] ?? $s['thana'] ?? ''),
                'district' => (string) ($s['district'] ?? $s['police_station_address'] ?? ''),
            ])->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
