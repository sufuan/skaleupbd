<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;

/** Paperfly has no verified public booking API (plan §10.7c, brief §7):
 *  booking follows the manual-CN path until a real seller account verifies one. */
class PaperflyDriver extends ManualDriver
{
    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array
    {
        $cn = trim((string) ($opts['cn'] ?? ''));
        if ($cn === '') {
            return ['ok' => false, 'error' => 'Paperfly booking API is not verified yet — book in Paperfly and record the CN number here', 'manual_fallback' => true];
        }

        return ['ok' => true, 'tracking_id' => $cn, 'consignment_id' => $cn, 'raw' => ['manual_cn' => $cn]];
    }

    public function testConnection(CourierMethod $method): array
    {
        return ['ok' => true, 'message' => 'Paperfly books by recorded CN for now (API unverified)'];
    }
}
