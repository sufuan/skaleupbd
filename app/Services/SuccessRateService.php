<?php

namespace App\Services;

use App\Models\CourierMethod;
use App\Models\Customer;
use App\Models\IpPhoneBlock;
use App\Models\Order;
use App\Services\Couriers\DriverFactory;
use Illuminate\Support\Facades\Cache;

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

        // Courier network check (M3 join, brief §1.8): the couriers' own
        // network-wide record for this phone is the headline figure when any
        // connected courier answers; this shop's own history stays alongside.
        $network = $this->networkCheck($businessId, $phone);
        $headlineRate = $rate;
        $headline = 'history';
        $networkRates = collect($network)->filter(fn ($n) => $n['rate'] !== null);
        if ($networkRates->isNotEmpty()) {
            $weightSum = 0.0;
            $weighted = 0.0;
            foreach ($networkRates as $n) {
                $w = (($n['delivered'] ?? 0) + ($n['returned'] ?? 0)) > 0 ? ($n['delivered'] + $n['returned']) : 10;
                $weighted += $n['rate'] * $w;
                $weightSum += $w;
            }
            $headlineRate = (int) round($weighted / max(1, $weightSum));
            $headline = 'network';
        }

        if ($blocked || $blacklisted) {
            $verdict = 'risky';
            $note = 'This number is on the block list';
        } elseif ($headlineRate === null) {
            $verdict = 'not_checked';
            $note = 'No delivery history yet';
        } elseif ($headlineRate >= 60) {
            $verdict = 'safe';
            $note = "{$orders} orders · {$delivered} delivered · {$returned} returned";
        } else {
            $verdict = 'risky';
            $note = "{$orders} orders · {$delivered} delivered · {$returned} returned";
        }
        if ($headline === 'network' && ! ($blocked || $blacklisted)) {
            $note = 'Courier network ' . $headlineRate . '% · this shop: ' . $note;
        }

        return ['rate' => $headlineRate, 'verdict' => $verdict, 'note' => $note,
            'orders' => $orders, 'delivered' => $delivered, 'returned' => $returned,
            'network' => $network, 'headline' => $headline];
    }

    /** @return array<int, array{courier:string, rate:?float, delivered:?int, returned:?int, label:?string}> */
    /** @var array<int, \Illuminate\Support\Collection> */
    private array $methodsCache = [];

    private function networkCheck(int $businessId, string $phone): array
    {
        $required = [
            'STEADFAST' => ['api_key', 'api_secret'],
            'PATHAO' => ['client_id', 'client_secret', 'client_email', 'password'],
            'ECOURIER' => ['api_key', 'api_secret', 'user_id'],
        ];
        $methods = $this->methodsCache[$businessId] ??= CourierMethod::where('business_id', $businessId)
            ->where('is_active', true)
            ->whereIn('courier_type', array_keys($required))
            ->get();
        $out = [];
        foreach ($methods as $method) {
            if (! config('couriers.mock') && ! DriverFactory::hasRealCredentials($method, $required[$method->courier_type])) {
                continue; // placeholder credentials — not really connected, never call out
            }
            $result = Cache::remember(
                'fraudnet:' . $businessId . ':' . $phone . ':' . $method->id,
                now()->addMinutes(10),
                fn () => DriverFactory::for($method)->fraudCheck($phone, $method)
            );
            if ($result !== null) {
                $out[] = ['courier' => $method->label()] + $result;
            }
        }

        return $out;
    }
}
