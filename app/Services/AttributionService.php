<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SmsLog;
use Carbon\Carbon;

/**
 * SMS Report attribution (plan v3 M12): an order is credited to the most
 * recent MARKETING SMS (campaign or marketing automation flow) that reached
 * the customer's phone within the attribution window before the order.
 * Transactional flows (thank-you, shipped, delivered, advance) never earn
 * credit — they are order news, not marketing.
 */
class AttributionService
{
    public function __construct(private readonly SmsService $sms) {}

    public function windowDays(int $biz): int
    {
        return max(1, (int) $this->sms->setting($biz, 'attribution_days', 7));
    }

    /**
     * Per-source aggregates for orders placed in [$from, $to]:
     * key "type:key" => [type, key, label, sent, orders, sales, sms_cost, profit]
     * 'sent' counts marketing SMS sent in the same period; 'profit' is the
     * credited DELIVERED orders' profit (total − item cost − delivery charge)
     * minus the source's SMS cost in the period.
     */
    public function report(int $biz, Carbon $from, Carbon $to): array
    {
        $window = $this->windowDays($biz);
        $marketingKeys = SmsService::MARKETING_FLOWS;

        $logs = SmsLog::where('business_id', $biz)->where('status', 'sent')
            ->where(function ($q) use ($marketingKeys) {
                $q->where('source_type', 'campaign')->orWhereIn('source_key', $marketingKeys);
            })
            ->where('created_at', '>=', $from->copy()->subDays($window))
            ->where('created_at', '<=', $to)
            ->orderBy('created_at')->get();

        $sources = [];
        $ensure = function (SmsLog $log) use (&$sources) {
            $k = $log->source_type.':'.$log->source_key;
            if (! isset($sources[$k])) {
                $sources[$k] = ['type' => $log->source_type, 'key' => $log->source_key, 'label' => $log->source_label,
                    'sent' => 0, 'orders' => 0, 'sales' => 0.0, 'sms_cost' => 0.0, 'profit' => 0.0];
            }

            return $k;
        };
        foreach ($logs as $log) {
            $k = $ensure($log);
            if ($log->created_at->between($from, $to)) {
                $sources[$k]['sent']++;
                $sources[$k]['sms_cost'] += (float) $log->cost;
            }
        }

        $byPhone = $logs->groupBy('phone');
        $orders = Order::where('business_id', $biz)->whereNotIn('status', ['Cancelled'])
            ->whereBetween('created_at', [$from, $to])->with('items')->get();
        foreach ($orders as $order) {
            $candidates = $byPhone->get($order->customer_phone);
            if (! $candidates) {
                continue;
            }
            $best = null;
            foreach ($candidates as $log) {
                if ($log->created_at->lt($order->created_at)
                    && $log->created_at->gte($order->created_at->copy()->subDays($window))
                    && (! $best || $log->created_at->gt($best->created_at))) {
                    $best = $log;
                }
            }
            if (! $best) {
                continue;
            }
            $k = $ensure($best);
            $sources[$k]['orders']++;
            $sources[$k]['sales'] += (float) $order->grand_total;
            if ($order->status === 'Delivered') {
                $itemCost = $order->items->sum(fn ($i) => (float) $i->cost * (int) $i->qty);
                $sources[$k]['profit'] += (float) $order->grand_total - $itemCost - (float) $order->delivery_charge;
            }
        }
        foreach ($sources as &$s) {
            $s['profit'] = round($s['profit'] - $s['sms_cost'], 2);
            $s['sales'] = round($s['sales'], 2);
            $s['sms_cost'] = round($s['sms_cost'], 2);
        }

        return array_values($sources);
    }
}
