<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\CourierSettlementAllocation;
use App\Models\Order;

/** Fixture driver for local end-to-end testing (config couriers.mock).
 *  Deterministic: a booking fails for phone numbers ending in 0000 so the
 *  failure path can be exercised; everything else succeeds. */
class MockDriver implements CourierDriver
{
    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array
    {
        if (str_ends_with((string) $order->customer_phone, '0000')) {
            return ['ok' => false, 'error' => __('Courier rejected the booking: recipient phone unreachable (mock failure)')];
        }
        $prefix = strtoupper(substr($method->courier_type, 0, 3));

        return [
            'ok' => true,
            'tracking_id' => $prefix . '-' . $order->invoice,
            'consignment_id' => 'CN' . strtoupper(substr(md5($order->invoice . $method->courier_type), 0, 10)),
            'raw' => ['mock' => true, 'courier' => $method->courier_type],
        ];
    }

    public function getStatus(Order $order, CourierMethod $method): array
    {
        return ['ok' => true, 'courier_status' => 'In Transit', 'raw' => ['mock' => true]];
    }

    public function cancelBooking(Order $order, CourierMethod $method): array
    {
        return ['ok' => true];
    }

    public function fraudCheck(string $phone, CourierMethod $method): ?array
    {
        return match ($phone) {
            '01712345678' => ['rate' => 80.0, 'delivered' => 40, 'returned' => 10, 'label' => null],
            '01898765432' => ['rate' => 25.0, 'delivered' => 5, 'returned' => 15, 'label' => null],
            default => null,
        };
    }

    public function testConnection(CourierMethod $method): array
    {
        return ['ok' => true, 'message' => __('Connection OK (mock driver)')];
    }

    public function syncStores(CourierMethod $method): array
    {
        if ($method->courier_type !== 'PATHAO') {
            return [];
        }

        return [
            ['store_id' => 'ST-100', 'name' => 'Main Store - Dhaka'],
            ['store_id' => 'ST-200', 'name' => 'Branch Store - Chattogram'],
        ];
    }

    public function getPayments(CourierMethod $method): array
    {
        $orders = $this->unsettledDelivered($method);
        if ($orders->isEmpty()) {
            return [];
        }
        $amount = $orders->sum(fn (Order $o) => max(0, $o->codAmount() - $method->chargeFor($o->effectiveDistrict())));

        return [[
            'payment_id' => 'SFC-MOCK-1', 'amount' => round($amount, 2), 'method' => 'Bank',
            'status_label' => 'Paid', 'paid_at' => now()->toDateTimeString(),
        ]];
    }

    public function getPaymentConsignments(CourierMethod $method, string $paymentId): array
    {
        return $this->unsettledDelivered($method)->map(fn (Order $o) => [
            'invoice' => $o->invoice,
            'amount' => round(max(0, $o->codAmount() - $method->chargeFor($o->effectiveDistrict())), 2),
        ])->all();
    }

    public function parcelCollection(Order $order, CourierMethod $method): ?array
    {
        if ($order->status !== 'Delivered') {
            return null;
        }

        return [
            'collected' => $order->codAmount(),
            'cod_fee' => 0.0,
            'delivery_fee' => $method->chargeFor($order->effectiveDistrict()),
            'payment_status' => 'Collected',
        ];
    }

    private function unsettledDelivered(CourierMethod $method)
    {
        $settledOrderIds = CourierSettlementAllocation::whereHas('settlement', fn ($q) => $q->where('courier_method_id', $method->id))
            ->pluck('order_id');

        return Order::where('business_id', $method->business_id)
            ->where('courier_method_id', $method->id)
            ->where('status', 'Delivered')
            ->whereNotIn('id', $settledOrderIds)
            ->get();
    }
}
