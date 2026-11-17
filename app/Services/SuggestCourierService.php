<?php

namespace App\Services;

use App\Models\CourierAssignmentRule;
use App\Models\CourierMethod;
use App\Models\Order;
use Illuminate\Support\Collection;

/** The §10.7b suggestion chain — first match wins; only active methods qualify. */
class SuggestCourierService
{
    /** @var array<int, \Illuminate\Support\Collection> */
    private array $rulesCache = [];

    /** @return array{method:CourierMethod, reason:string, pickup_store_id:?int}|null */
    public function suggest(Order $order, ?Collection $methods = null): ?array
    {
        $methods ??= CourierMethod::where('business_id', $order->business_id)->where('is_active', true)->get();
        $byId = $methods->keyBy('id');
        if ($methods->isEmpty()) {
            return null;
        }

        // 1. Manual override — a courier staff already set on the order.
        if ($order->courier_method_id && $byId->has($order->courier_method_id)) {
            return ['method' => $byId[$order->courier_method_id], 'reason' => 'Chosen on the order', 'pickup_store_id' => $order->pickup_store_id];
        }
        if ($order->delivery_method) {
            foreach ($methods as $m) {
                if (str_starts_with((string) $order->delivery_method, $m->label())) {
                    return ['method' => $m, 'reason' => 'Chosen on the order', 'pickup_store_id' => $order->pickup_store_id];
                }
            }
        }

        // 2 + 3. Assignment rules (product / category / district / area), priority order.
        $rules = $this->rulesCache[$order->business_id] ??= CourierAssignmentRule::where('business_id', $order->business_id)
            ->where('is_active', true)->orderBy('priority')->orderBy('id')->get();
        $itemProductIds = $order->items->pluck('product_id')->filter()->all();
        $itemNames = $order->items->map(fn ($i) => strtolower((string) $i->name))->all();
        foreach ($rules as $rule) {
            if (! $byId->has($rule->courier_method_id)) {
                continue;
            }
            $hit = match ($rule->scope) {
                'product' => $rule->product_id && in_array($rule->product_id, $itemProductIds),
                'category' => $rule->category && collect($itemNames)->contains(fn ($n) => str_contains($n, strtolower($rule->category))),
                'district' => $rule->district && strtolower((string) $rule->district) === strtolower((string) $order->district),
                'area' => $rule->area && strtolower((string) $rule->area) === strtolower((string) $order->area),
                default => false,
            };
            if ($hit) {
                $reason = match ($rule->scope) {
                    'product' => 'Product rule', 'category' => 'Category rule',
                    'district' => 'District rule', 'area' => 'Area rule', default => 'Rule',
                };

                return ['method' => $byId[$rule->courier_method_id], 'reason' => $reason, 'pickup_store_id' => $rule->pickup_store_id];
            }
        }

        // 4. Seller default / preferred courier.
        $preferred = $methods->firstWhere('is_preferred', true);
        if ($preferred) {
            return ['method' => $preferred, 'reason' => 'Default courier', 'pickup_store_id' => null];
        }
        $default = $methods->firstWhere('is_default', true);
        if ($default) {
            return ['method' => $default, 'reason' => 'Default courier', 'pickup_store_id' => null];
        }

        // 5. Best historical success rate — this customer first, then this district.
        $best = $this->bestByRate($methods, fn ($q) => $q->where('customer_phone', $order->customer_phone));
        if ($best) {
            return ['method' => $best, 'reason' => 'Best success rate for this customer', 'pickup_store_id' => null];
        }
        $best = $this->bestByRate($methods, fn ($q) => $q->where('district', $order->district));
        if ($best) {
            return ['method' => $best, 'reason' => 'Best success rate in this area', 'pickup_store_id' => null];
        }

        return ['method' => $methods->first(), 'reason' => 'Only courier available', 'pickup_store_id' => null];
    }

    private function bestByRate(Collection $methods, callable $scope): ?CourierMethod
    {
        $best = null;
        $bestRate = -1;
        foreach ($methods as $m) {
            $base = Order::where('business_id', $m->business_id)->where('courier_method_id', $m->id);
            $scope($base);
            $delivered = (clone $base)->where('status', 'Delivered')->count();
            $returned = (clone $base)->where('status', 'Returned')->count();
            $total = $delivered + $returned;
            if ($total === 0) {
                continue;
            }
            $rate = $delivered / $total * 100;
            if ($rate > $bestRate) {
                $bestRate = $rate;
                $best = $m;
            }
        }

        return $best;
    }
}
