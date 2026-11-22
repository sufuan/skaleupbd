<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\OrderItem;
use App\Models\OrderSource;
use App\Models\Product;
use App\Models\User;
use App\Models\WorkTask;
use App\Support\DhakaTime;
use Carbon\Carbon;

/**
 * Every number on the M1 Dashboard is defined here, once. The controller
 * only passes these arrays to the page, so two blocks that show the same
 * figure always agree (the Sales Growth reconciliation standard).
 *
 * Shared definitions:
 *  - A "day" is a Dhaka calendar day; stored timestamps are UTC instants,
 *    so day windows use DhakaTime::dayBoundsUtc().
 *  - Revenue of a set of orders = Σ grand_total of the Delivered ones.
 *  - Profit of a set of orders = revenue − Σ(item cost × qty) − Σ delivery
 *    charge of those same Delivered orders.
 *  - A "cohort" is the set of orders counted on one date basis
 *    (approved_at / created_at / shipped_at) for one day.
 */
class DashboardStatsService
{
    /** Date-basis column for the My Dashboard counting-basis switch. */
    public const BASES = [
        'approved' => 'approved_at',
        'created' => 'created_at',
        'courier' => 'shipped_at',
    ];

    private function base(int $biz)
    {
        return Order::where('business_id', $biz);
    }

    private function onDay($q, string $col, string $dhakaDate)
    {
        return $q->whereBetween($col, DhakaTime::dayBoundsUtc($dhakaDate));
    }

    /** @return array{0: float, 1: float} [revenue, profit] of a loaded order collection. */
    private function money($orders): array
    {
        $delivered = $orders->where('status', 'Delivered');
        $revenue = (float) $delivered->sum('grand_total');
        $costs = (float) $delivered->sum(fn ($o) => $o->items->sum(fn ($i) => (float) $i->cost * $i->qty) + (float) $o->delivery_charge);

        return [$revenue, $revenue - $costs];
    }

    private function pct(float $cur, float $old): ?float
    {
        return $old == 0.0 ? null : round(($cur - $old) / abs($old) * 100, 1);
    }

    // ---------------------------------------------------------------- My Dashboard

    public function myDashboard(int $biz, string $dayStr, string $basis): array
    {
        $col = self::BASES[$basis] ?? 'approved_at';
        $day = Carbon::parse($dayStr, 'Asia/Dhaka');
        $prevDay = $day->copy()->subDay();
        $todayStr = DhakaTime::today();
        $todayD = Carbon::parse($todayStr, 'Asia/Dhaka');

        $cohort = fn (Carbon $d) => $this->onDay($this->base($biz), $col, $d->toDateString())->with('items')->get();
        $kpiFor = function (Carbon $d) use ($cohort) {
            $orders = $cohort($d);
            [$rev, $profit] = $this->money($orders);

            return ['orders' => $orders->count(), 'revenue' => $rev, 'profit' => $profit];
        };
        $cur = $kpiFor($day);
        $old = $kpiFor($prevDay);
        $kpis = [];
        foreach (['orders', 'revenue', 'profit'] as $k) {
            $kpis[$k] = ['value' => $cur[$k], 'prev' => $old[$k], 'pct' => $this->pct((float) $cur[$k], (float) $old[$k])];
        }

        $sourceLabels = OrderSource::where('business_id', $biz)->pluck('label', 'code');
        $srcRows = $cohort($day)->groupBy('source')
            ->map(fn ($g, $code) => ['label' => $sourceLabels[$code] ?? $code, 'count' => $g->count(), 'value' => (float) $g->sum('grand_total')])
            ->sortByDesc('count')->values();
        $channelOf = fn (string $code) => match (true) {
            in_array($code, ['FB', 'MESSENGER', 'INSTAGRAM', 'WHATSAPP', 'TIKTOK'], true) => 'Social',
            $code === 'WEB' => 'Website',
            $code === 'DIRECT' => 'Direct',
            default => 'Other',
        };
        $chanRows = $cohort($day)->groupBy(fn ($o) => $channelOf($o->source))
            ->map(fn ($g, $name) => ['label' => $name, 'count' => $g->count(), 'value' => (float) $g->sum('grand_total')])
            ->sortByDesc('count')->values();

        // Inflow charts stay created-based by definition.
        $webSeries = collect(range(6, 0))->map(fn ($i) => $day->copy()->subDays($i))->map(fn ($d) => [
            'date' => $d->format('M j'),
            'web' => $this->onDay($this->base($biz)->where('source', 'WEB'), 'created_at', $d->toDateString())->count(),
            'inc' => $this->onDay($this->base($biz)->where('source', 'INCOMPLETE_ORDER'), 'created_at', $d->toDateString())->count(),
        ])->all();

        $counts30 = collect(range(29, 0))->map(fn ($i) => $todayD->copy()->subDays($i))->map(fn ($d) => [
            'date' => $d->format('M j'),
            'created' => $this->onDay($this->base($biz), 'created_at', $d->toDateString())->count(),
            'sent' => $this->onDay($this->base($biz)->whereNotNull('shipped_at'), 'shipped_at', $d->toDateString())->count(),
        ])->all();

        $hourlyFor = function (Carbon $d) use ($biz) {
            $rows = $this->onDay($this->base($biz), 'created_at', $d->toDateString())->get(['created_at', 'source', 'status']);
            $mk = fn (callable $f) => collect(range(0, 23))
                ->map(fn ($h) => $rows->filter(fn ($o) => (int) $o->created_at->copy()->setTimezone('Asia/Dhaka')->format('G') === $h && $f($o))->count())->all();

            return ['combined' => $mk(fn () => true), 'web' => $mk(fn ($o) => $o->source === 'WEB'), 'inc' => $mk(fn ($o) => $o->source === 'INCOMPLETE_ORDER')];
        };

        $cohortIds = $cohort($day)->pluck('id');
        $topRows = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.id', $cohortIds)
            ->selectRaw('order_items.name as name, order_items.sku as sku, sum(order_items.qty) as qty')
            ->groupBy('order_items.name', 'order_items.sku')->orderByDesc('qty')->limit(5)->get();
        $returnedQty = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.id', $cohortIds)->where('orders.status', 'Returned')
            ->selectRaw('order_items.sku as sku, sum(order_items.qty) as qty')->groupBy('order_items.sku')->pluck('qty', 'sku');
        $topProducts = $topRows->map(fn ($r) => [
            'name' => $r->name, 'sku' => $r->sku, 'qty' => (int) $r->qty,
            'return_pct' => (float) $r->qty > 0 ? round(((float) ($returnedQty[$r->sku] ?? 0)) / (float) $r->qty * 100, 1) : 0.0,
        ])->all();

        return [
            'day' => $dayStr,
            'is_today' => $dayStr === $todayStr,
            'day_label' => $day->format('j M Y'),
            'day_short' => $day->format('j M'),
            'prev_short' => $prevDay->format('j M'),
            'prev_iso' => $prevDay->format('Y-m-d'),
            'until_hour' => $dayStr === $todayStr ? (int) now('Asia/Dhaka')->format('G') : 23,
            'basis' => $basis,
            'kpis' => $kpis,
            'pending_web' => $this->base($biz)->where('status', 'Pending')->where('source', 'WEB')->count(),
            'web_series' => $webSeries,
            'sources' => $srcRows,
            'channels' => $chanRows,
            'counts30' => $counts30,
            'hourly_day' => $hourlyFor($day),
            'hourly_prev' => $hourlyFor($prevDay),
            'top_products' => $topProducts,
        ];
    }

    // ---------------------------------------------------------------- Overview

    /** Last-30-Dhaka-days window ending on $endStr (created_at cohort). */
    public function overview(int $biz, string $endStr): array
    {
        $end = Carbon::parse($endStr, 'Asia/Dhaka');
        $win = fn (Carbon $from, Carbon $to) => $this->base($biz)
            ->whereBetween('created_at', [DhakaTime::dayBoundsUtc($from->toDateString())[0], DhakaTime::dayBoundsUtc($to->toDateString())[1]]);

        $orders = $win($end->copy()->subDays(29), $end)->with('items')->get();
        $prevOrders = $win($end->copy()->subDays(59), $end->copy()->subDays(30))->with('items')->get();
        $byStatus = fn (string $s) => $orders->where('status', $s)->count();
        [$rev, $profit] = $this->money($orders);
        [$prevRev] = $this->money($prevOrders);
        $total = $orders->count();
        $cancelled = $byStatus('Cancelled');

        [$dayStart] = DhakaTime::dayBoundsUtc($endStr);
        $dayEnd = DhakaTime::dayBoundsUtc($endStr)[1];
        $courierCost = (float) app(FinanceService::class)->figures($biz, $dayStart, $dayEnd)['courierCharges'];
        $pendingCod = (float) app(SettlementService::class)->ledger($biz)['total']['still'];

        return [
            'received' => $total,
            'approved' => $byStatus('Processing') + $byStatus('Shipped') + $byStatus('Delivered'),
            'processing' => $byStatus('Processing'),
            'shipped' => $byStatus('Shipped'),
            'delivered' => $byStatus('Delivered'),
            'cancelled' => $cancelled,
            'cancelled_pct' => $total > 0 ? (int) round($cancelled / $total * 100) : 0,
            'returned' => $byStatus('Returned'),
            'incomplete' => $byStatus('Incomplete'),
            'web' => $orders->where('source', 'WEB')->count(),
            'revenue' => $rev,
            'revenue_prev' => $prevRev,
            'revenue_pct' => $this->pct($rev, $prevRev),
            'profit' => $profit,
            'ad_spend' => 0.0,
            'courier_cost' => $courierCost,
            'pending_cod' => $pendingCod,
            'sent' => $orders->whereNotNull('shipped_at')->count(),
        ];
    }

    // ---------------------------------------------------------------- Decision

    /**
     * Work Inbox health. A "live rule" is a catalog rule that has an
     * evaluator method in WorkInboxService; confidence is the share of
     * live rules with no open task right now.
     */
    public function decision(int $biz): array
    {
        $svc = app(WorkInboxService::class);
        $live = collect(WorkInboxService::catalog())->keys()
            ->filter(fn ($key) => method_exists($svc, 'rule' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)))))
            ->values();
        $open = WorkTask::where('business_id', $biz)->whereIn('status', WorkTask::OPEN_STATUSES);
        $breaching = (clone $open)->whereIn('rule_key', $live)->distinct()->count('rule_key');
        $openTasks = (clone $open)->count();
        $critical = (clone $open)->where('priority', 'critical')->count();
        $liveCount = $live->count();

        return [
            'confidence' => $liveCount > 0 ? (int) round(($liveCount - $breaching) / $liveCount * 100) : 100,
            'live_rules' => $liveCount,
            'breaching' => $breaching,
            'open_tasks' => $openTasks,
            'critical_open' => $critical,
            'verdict' => $critical > 0 ? 'major' : ($breaching > 0 ? 'some' : 'none'),
        ];
    }

    // ---------------------------------------------------------------- Operations

    /** Open Work Task counts per prototype stuck box + the not-sent-to-courier table. */
    public function operations(int $biz): array
    {
        $open = WorkTask::where('business_id', $biz)->whereIn('status', WorkTask::OPEN_STATUSES);
        $byRules = fn (array $keys) => (clone $open)->whereIn('rule_key', $keys)->count();

        $stuck = [
            ['label' => 'Web orders stuck', 'count' => $byRules(['pending_stuck'])],
            ['label' => 'RTS not sent', 'count' => $byRules(['ready_to_ship'])],
            ['label' => 'Courier hold', 'count' => $byRules(['courier_hold'])],
            ['label' => 'Pending return', 'count' => $byRules(['return_undecided'])],
            ['label' => 'Shipped too long', 'count' => $byRules(['shipped_stuck'])],
            ['label' => 'Callback missed', 'count' => $byRules(['callback_missed'])],
            ['label' => 'Unassigned work', 'count' => (clone $open)->where('assignee_mode', 'pool')->whereNull('assignee_user_id')->count()],
            ['label' => 'Chat follow-up', 'count' => $byRules(['ai_handover', 'window_closing', 'ai_draft_stuck', 'comment_reply'])],
            ['label' => 'Warranty overdue', 'count' => $byRules(['warranty_overdue'])],
            ['label' => 'Courier issue', 'count' => $byRules(['booking_failing', 'parcel_lost_damaged'])],
        ];

        $notSentQ = $this->base($biz)->whereIn('status', ['Pending', 'Processing']);
        $notSentTotal = (clone $notSentQ)->count();
        $rows = (clone $notSentQ)->latest()->limit(5)->get()
            ->map(fn ($o) => ['invoice' => $o->invoice_no, 'name' => $o->customer_name,
                'courier' => $o->delivery_method ? strtolower(strtok($o->delivery_method, ' ')) : '-', 'status' => $o->status]);
        $couriers = (clone $notSentQ)->whereNotNull('delivery_method')->pluck('delivery_method')
            ->map(fn ($m) => strtolower(strtok($m, ' ')))->unique()->values();

        return ['stuck' => $stuck, 'not_sent_total' => $notSentTotal, 'courier_rows' => $rows, 'couriers' => $couriers];
    }

    // ---------------------------------------------------------------- Products

    public function products(int $biz, string $endStr): array
    {
        $end = Carbon::parse($endStr, 'Asia/Dhaka');
        [$fromUtc] = DhakaTime::dayBoundsUtc($end->copy()->subDays(29)->toDateString());
        [, $toUtc] = DhakaTime::dayBoundsUtc($endStr);

        $soldWin = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.business_id', $biz)->where('orders.status', '!=', 'Cancelled')
            ->whereBetween('orders.created_at', [$fromUtc, $toUtc])
            ->selectRaw('order_items.name as name, sum(order_items.qty) as qty')
            ->groupBy('order_items.name')->orderByDesc('qty')->limit(8)->get()
            ->map(fn ($r) => ['name' => $r->name, 'qty' => (int) $r->qty])->all();

        $soldAll = OrderItem::query()->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.business_id', $biz)->where('orders.status', '!=', 'Cancelled')
            ->selectRaw('order_items.product_id as pid, sum(order_items.qty) as qty')
            ->groupBy('order_items.product_id')->pluck('qty', 'pid');

        $products = Product::where('business_id', $biz)->where('is_active', true)->get();
        $low = $products->filter(function ($p) use ($soldAll) {
            $available = (int) $p->stock_qty - (int) $p->reserved_qty;

            return $available <= (int) $p->alert_qty || (int) ($soldAll[$p->id] ?? 0) === 0;
        })->map(fn ($p) => [
            'name' => $p->name,
            'stock' => (int) $p->stock_qty - (int) $p->reserved_qty,
            'sold' => (int) ($soldAll[$p->id] ?? 0),
        ])->sortBy('stock')->values()->take(10)->all();

        return [
            'top' => $soldWin,
            'low_rows' => $low,
            'low_total_stock' => (int) $products->sum(fn ($p) => (int) $p->stock_qty - (int) $p->reserved_qty),
            'low_count' => (int) $products->filter(fn ($p) => (int) $p->stock_qty - (int) $p->reserved_qty <= (int) $p->alert_qty)->count(),
            'total_sold' => (int) $soldAll->sum(),
        ];
    }

    // ---------------------------------------------------------------- Marketing

    public function marketing(int $biz, string $dayStr): array
    {
        $day = Carbon::parse($dayStr, 'Asia/Dhaka');
        $profitDays = collect(range(4, 0))->map(function ($i) use ($biz, $day) {
            $d = $day->copy()->subDays($i);
            $orders = $this->onDay($this->base($biz), 'created_at', $d->toDateString())->with('items')->get();
            [, $profit] = $this->money($orders);

            return ['label' => $d->format('M j'), 'profit' => $profit];
        })->all();

        $rows = $this->onDay($this->base($biz), 'created_at', $dayStr)->get(['created_at']);
        $hours = collect(range(0, 23))
            ->map(fn ($h) => $rows->filter(fn ($o) => (int) $o->created_at->copy()->setTimezone('Asia/Dhaka')->format('G') === $h)->count())->all();

        return [
            'profit_days' => $profitDays,
            'ad_spend' => 0.0,
            'ad_revenue' => 0.0,
            'heatmap_hours' => $hours,
            'heatmap_weekday' => $day->format('l'),
        ];
    }

    // ---------------------------------------------------------------- Customers & Team

    public function team(int $biz, string $endStr): array
    {
        $end = Carbon::parse($endStr, 'Asia/Dhaka');
        [$fromUtc] = DhakaTime::dayBoundsUtc($end->copy()->subDays(29)->toDateString());
        [, $toUtc] = DhakaTime::dayBoundsUtc($endStr);

        // The Reports module's precedent: processed orders by processed_by,
        // windowed on updated_at.
        $byAgent = $this->base($biz)->whereNotNull('processed_by')
            ->whereBetween('updated_at', [$fromUtc, $toUtc])->get()
            ->groupBy('processed_by');
        $names = User::whereIn('id', $byAgent->keys())->pluck('name', 'id');
        $agents = $byAgent->map(function ($orders, $uid) use ($names) {
            $processed = $orders->count();
            $still = $orders->where('status', 'Processing')->count();

            return [
                'name' => $names[$uid] ?? '-',
                'processed' => $processed,
                'processing_pct' => $processed > 0 ? (int) round($still / $processed * 100) : 0,
                'revenue' => (float) $orders->where('status', '!=', 'Cancelled')->sum('grand_total'),
            ];
        })->sortByDesc('processed')->values()
            ->map(fn ($a, $i) => ['rank' => $i + 1] + $a)->all();

        $customers = Customer::where('business_id', $biz)->get();
        $orderCounts = $this->base($biz)->whereNotNull('customer_id')
            ->selectRaw('customer_id, count(*) as c')->groupBy('customer_id')->pluck('c', 'customer_id');
        $repeat = $customers->filter(fn ($c) => (int) ($orderCounts[$c->id] ?? 0) >= 2)->count();
        $one = $customers->filter(fn ($c) => (int) ($orderCounts[$c->id] ?? 0) === 1)->count();
        $none = $customers->count() - $repeat - $one;
        $districts = $customers->groupBy(fn ($c) => trim((string) $c->district) !== '' ? trim((string) $c->district) : 'Unknown')
            ->map->count()->sortDesc();
        $topDistrict = $districts->keys()->first() ?? 'Unknown';

        return [
            'agents' => $agents,
            'customers' => [
                'total' => $customers->count(),
                'repeat' => $repeat,
                'one' => $one,
                'none' => $none,
                'repeat_rate' => $customers->count() > 0 ? round($repeat / $customers->count() * 100, 1) : 0.0,
                'district_label' => $topDistrict,
                'district_count' => (int) ($districts[$topDistrict] ?? 0),
            ],
        ];
    }

    // ---------------------------------------------------------------- Activity

    public function activity(int $biz, string $dayStr): array
    {
        $feed = OrderActivity::query()
            ->join('orders', 'orders.id', '=', 'order_activities.order_id')
            ->where('orders.business_id', $biz)
            ->orderByDesc('order_activities.created_at')->limit(12)
            ->get(['order_activities.text', 'order_activities.created_at', 'orders.invoice_no'])
            ->map(fn ($a) => [
                'text' => $a->text,
                'invoice' => $a->invoice_no,
                'when' => DhakaTime::fmt($a->created_at, 'j M, g:i A'),
            ])->all();

        return [
            'feed' => $feed,
            'web_day' => $this->onDay($this->base($biz)->where('source', 'WEB'), 'created_at', $dayStr)->count(),
            'inc_day' => $this->onDay($this->base($biz)->where('source', 'INCOMPLETE_ORDER'), 'created_at', $dayStr)->count(),
            'created_day' => $this->onDay($this->base($biz), 'created_at', $dayStr)->count(),
            'sent_day' => $this->onDay($this->base($biz)->whereNotNull('shipped_at'), 'shipped_at', $dayStr)->count(),
        ];
    }
}
