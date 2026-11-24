<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;

class ManualDriver implements CourierDriver
{
    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array
    {
        $cn = trim((string) ($opts['cn'] ?? ''));
        if ($cn === '') {
            return ['ok' => false, 'error' => __('Enter the CN number for this manual booking')];
        }

        return ['ok' => true, 'tracking_id' => $cn, 'consignment_id' => $cn, 'raw' => ['manual_cn' => $cn]];
    }

    public function getStatus(Order $order, CourierMethod $method): array
    {
        return ['ok' => false, 'error' => __('Manual courier - update the status by hand (Scan To Update)')];
    }

    public function cancelBooking(Order $order, CourierMethod $method): array
    {
        return ['ok' => true];
    }

    public function fraudCheck(string $phone, CourierMethod $method): ?array
    {
        return null;
    }

    public function testConnection(CourierMethod $method): array
    {
        return ['ok' => true, 'message' => __('Manual courier - no connection to test')];
    }

    public function syncStores(CourierMethod $method): array
    {
        return [];
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
