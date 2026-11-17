<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;
use App\Models\Order;

/** Bahok endpoints are unverified (brief §7) — same manual-CN treatment as Paperfly. */
class BahokDriver extends ManualDriver
{
    public function createBooking(Order $order, CourierMethod $method, array $opts = []): array
    {
        $cn = trim((string) ($opts['cn'] ?? ''));
        if ($cn === '') {
            return ['ok' => false, 'error' => 'Bahok booking API is not verified yet — book with Bahok and record the CN number here', 'manual_fallback' => true];
        }

        return ['ok' => true, 'tracking_id' => $cn, 'consignment_id' => $cn, 'raw' => ['manual_cn' => $cn]];
    }
}
