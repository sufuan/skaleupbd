<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\IpPhoneBlock;
use App\Models\Order;

/**
 * Fraud / success-rate signal for a customer phone inside one business.
 * (a) own-history record computed from the platform's own orders;
 * (b) block-list match. Courier-network checks join here when M3 wires
 * the seller's courier credentials.
 */
class SuccessRateService
{
    /** @return array{rate:?int, verdict:string, note:string, orders:int, delivered:int, returned:int} */
    public function forPhone(int $businessId, string $phone): array
    {
        $phone = PhoneNormalizer::normalize($phone);

        $base = Order::where('business_id', $businessId)->where('customer_phone', $phone);
        $orders = (clone $base)->count();
        $delivered = (clone $base)->where('status', 'Delivered')->count();
        $returned = (clone $base)->whereIn('status', ['Returned'])->count();
        $outcomes = $delivered + $returned;
        $rate = $outcomes > 0 ? (int) round($delivered / $outcomes * 100) : null;

        $blocked = IpPhoneBlock::where('business_id', $businessId)
            ->where('type', 'phone')->where('value', $phone)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
        $blacklisted = Customer::where('business_id', $businessId)->where('phone', $phone)->value('is_blacklisted');

        if ($blocked || $blacklisted) {
            $verdict = 'risky';
            $note = 'This number is on the block list';
        } elseif ($rate === null) {
            $verdict = 'not_checked';
            $note = 'No delivery history yet';
        } elseif ($rate >= 60) {
            $verdict = 'safe';
            $note = "{$orders} orders · {$delivered} delivered · {$returned} returned";
        } else {
            $verdict = 'risky';
            $note = "{$orders} orders · {$delivered} delivered · {$returned} returned";
        }

        return ['rate' => $rate, 'verdict' => $verdict, 'note' => $note,
            'orders' => $orders, 'delivered' => $delivered, 'returned' => $returned];
    }
}
