<?php

namespace App\Services\Couriers;

use App\Models\CourierMethod;

class DriverFactory
{
    public static function for(CourierMethod $method): CourierDriver
    {
        if (config('couriers.mock') && $method->bookingMode() === 'api') {
            return new MockDriver();
        }

        return match ($method->courier_type) {
            'STEADFAST' => new SteadfastDriver(),
            'PATHAO' => new PathaoDriver(),
            'REDX' => new RedXDriver(),
            'CARRYBEE' => new CarrybeeDriver(),
            'ECOURIER' => new ECourierDriver(),
            'PAPERFLY' => new PaperflyDriver(),
            'BAHOK' => new BahokDriver(),
            default => new ManualDriver(),
        };
    }

    /** True when the saved credentials are empty or obvious placeholders — such a
     *  method is treated as not really connected (no live calls are attempted). */
    public static function hasRealCredentials(CourierMethod $method, array $requiredKeys): bool
    {
        $creds = $method->credentials ?? [];
        foreach ($requiredKeys as $key) {
            $v = strtolower(trim((string) ($creds[$key] ?? '')));
            if ($v === '' || in_array($v, ['demo', 'test', 'placeholder', 'xxxx'], true)) {
                return false;
            }
        }

        return true;
    }
}
