<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderSource;
use App\Support\DhakaTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $biz = (int) ($request->user()?->business_id ?? 0);
        $base = Order::where('business_id', $biz);
        $count = fn (string $s) => (clone $base)->where('status', $s)->count();

        $delivered = (clone $base)->where('status', 'Delivered')->with('items')->get();
        $revenue = (float) $delivered->sum('grand_total');
        $costs = (float) $delivered->sum(fn ($o) => $o->items->sum(fn ($i) => (float) $i->cost * $i->qty) + (float) $o->delivery_charge);
        $profit = $revenue - $costs;

        $sourceLabels = OrderSource::where('business_id', $biz)->pluck('label', 'code');
        $sources = (clone $base)->selectRaw('source, count(*) as c, sum(grand_total) as v')->groupBy('source')->get()
            ->map(fn ($r) => ['label' => $sourceLabels[$r->source] ?? $r->source, 'count' => (int) $r->c, 'value' => (float) $r->v])
            ->sortByDesc('count')->values();

        $notSent = (clone $base)->whereIn('status', ['Pending', 'Processing'])->with('items')->latest()->limit(5)->get()
            ->map(fn ($o) => ['invoice' => $o->invoice_no, 'name' => $o->customer_name,
                'courier' => $o->delivery_method ? strtolower(strtok($o->delivery_method, ' ')) : '—', 'status' => $o->status]);

        // ---- My Dashboard (prototype panel): one selected day vs the day before ----
        // Days are Dhaka calendar days — that is the seller's "today".
        // Stored timestamps are UTC instants, so a day's rows are the UTC
        // bounds of the Dhaka day; a plain whereDate() uses the UTC date
        // and pushes 12:00–5:59 AM Dhaka orders into "yesterday".
        $dayStr = $request->query('day');
        $dayStr = is_string($dayStr) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayStr) ? $dayStr : DhakaTime::today();
        $day = Carbon::parse($dayStr, 'Asia/Dhaka');
        $prevDay = $day->copy()->subDay();
        $todayD = Carbon::parse(DhakaTime::today(), 'Asia/Dhaka');
        $onDay = fn ($q, string $col, Carbon $dhakaDay) => $q->whereBetween($col, DhakaTime::dayBoundsUtc($dhakaDay->toDateString()));
        $kpiFor = function ($d) use ($base, $onDay) {
            $orders = $onDay(clone $base, 'created_at', $d)->with('items')->get();
            $delivered = $orders->where('status', 'Delivered');
            $rev = (float) $delivered->sum('grand_total');
            $cst = (float) $delivered->sum(fn ($o) => $o->items->sum(fn ($i) => (float) $i->cost * $i->qty) + (float) $o->delivery_charge);

            return ['orders' => $orders->count(), 'revenue' => $rev, 'profit' => $rev - $cst];
        };
        $cur = $kpiFor($day);
        $old = $kpiFor($prevDay);
        $pct = fn (float $c, float $o) => $o == 0.0 ? null : round(($c - $o) / abs($o) * 100, 1);
        $kpis = [];
        foreach (['orders', 'revenue', 'profit'] as $k) {
            $kpis[$k] = ['value' => $cur[$k], 'prev' => $old[$k], 'pct' => $pct((float) $cur[$k], (float) $old[$k])];
        }

        $srcRows = $onDay(clone $base, 'created_at', $day)
            ->selectRaw('source, count(*) as c, sum(grand_total) as v')->groupBy('source')->get();
        $srcDay = $srcRows->map(fn ($r) => ['label' => $sourceLabels[$r->source] ?? $r->source, 'count' => (int) $r->c, 'value' => (float) $r->v])
            ->sortByDesc('count')->values();
        $channelOf = fn (string $code) => match (true) {
            in_array($code, ['FB', 'MESSENGER', 'INSTAGRAM', 'WHATSAPP', 'TIKTOK'], true) => 'Social',
            $code === 'WEB' => 'Website',
            $code === 'DIRECT' => 'Direct',
            default => 'Other',
        };
        $chanDay = $srcRows->groupBy(fn ($r) => $channelOf($r->source))
            ->map(fn ($g, $name) => ['label' => $name, 'count' => (int) $g->sum('c'), 'value' => (float) $g->sum('v')])
            ->sortByDesc('count')->values();

        $webSeries = collect(range(6, 0))->map(fn ($i) => $day->copy()->subDays($i))->map(fn ($d) => [
            'date' => $d->format('M j'),
            'web' => $onDay((clone $base)->where('source', 'WEB'), 'created_at', $d)->count(),
            'inc' => $onDay((clone $base)->where('source', 'INCOMPLETE_ORDER'), 'created_at', $d)->count(),
        ])->all();

        $counts30 = collect(range(29, 0))->map(fn ($i) => $todayD->copy()->subDays($i))->map(fn ($d) => [
            'date' => $d->format('M j'),
            'created' => $onDay(clone $base, 'created_at', $d)->count(),
            'sent' => $onDay((clone $base)->whereNotNull('shipped_at'), 'shipped_at', $d)->count(),
        ])->all();

        $hourlyFor = function ($d) use ($base, $onDay) {
            $rows = $onDay(clone $base, 'created_at', $d)->get(['created_at', 'source']);
            $mk = fn (callable $f) => collect(range(0, 23))
                ->map(fn ($h) => $rows->filter(fn ($o) => (int) $o->created_at->copy()->setTimezone('Asia/Dhaka')->format('G') === $h && $f($o))->count())->all();

            return ['combined' => $mk(fn () => true), 'web' => $mk(fn ($o) => $o->source === 'WEB'), 'inc' => $mk(fn ($o) => $o->source === 'INCOMPLETE_ORDER')];
        };

        $topRows = \App\Models\OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.business_id', $biz)->whereBetween('orders.created_at', DhakaTime::dayBoundsUtc($dayStr))
            ->selectRaw('order_items.name as name, order_items.sku as sku, sum(order_items.qty) as qty')
            ->groupBy('order_items.name', 'order_items.sku')->orderByDesc('qty')->limit(5)->get();
        $returnedQty = \App\Models\OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.business_id', $biz)->where('orders.status', 'Returned')->whereBetween('orders.created_at', DhakaTime::dayBoundsUtc($dayStr))
            ->selectRaw('order_items.sku as sku, sum(order_items.qty) as qty')->groupBy('order_items.sku')->pluck('qty', 'sku');
        $topProducts = $topRows->map(fn ($r) => [
            'name' => $r->name, 'sku' => $r->sku, 'qty' => (int) $r->qty,
            'return_pct' => (float) $r->qty > 0 ? round(((float) ($returnedQty[$r->sku] ?? 0)) / (float) $r->qty * 100, 1) : 0.0,
        ])->all();

        return Inertia::render('Dashboard', [
            'billing' => (function () use ($biz) {
                if (! $biz || ! \Illuminate\Support\Facades\Schema::hasTable('business_wallets')) {
                    return null;
                }
                $tokens = app(\App\Services\TokenService::class);
                $wallet = $tokens->wallet($biz);
                $endingSoon = ($wallet?->cycle_end && $wallet->cycle_end->isFuture() && $wallet->cycle_end->lte(now()->addDays(3)))
                    || ($wallet?->wallet_expires_at && $wallet->wallet_expires_at->isFuture() && $wallet->wallet_expires_at->lte(now()->addDays(3)));

                return [
                    'balance' => $tokens->balance($biz),
                    'blocked' => $tokens->blocked($biz),
                    'low' => $tokens->isLow($biz),
                    'ending_soon' => (bool) $endingSoon,
                    'cycle_end' => DhakaTime::fmt($wallet?->cycle_end, 'd M Y'),
                    'wallet_expires_at' => DhakaTime::fmt($wallet?->wallet_expires_at, 'd M Y'),
                ];
            })(),
            'my' => [
                'day' => $day->format('Y-m-d'),
                'is_today' => $dayStr === DhakaTime::today(),
                'day_label' => $day->format('j M Y'),
                'day_short' => $day->format('j M'),
                'prev_short' => $prevDay->format('j M'),
                'prev_iso' => $prevDay->format('Y-m-d'),
                'until_hour' => $dayStr === DhakaTime::today() ? (int) now('Asia/Dhaka')->format('G') : 23,
                'kpis' => $kpis,
                'pending_web' => (clone $base)->where('status', 'Pending')->where('source', 'WEB')->count(),
                'web_series' => $webSeries,
                'sources' => $srcDay,
                'channels' => $chanDay,
                'counts30' => $counts30,
                'hourly_day' => $hourlyFor($day),
                'hourly_prev' => $hourlyFor($prevDay),
                'top_products' => $topProducts,
            ],
            'stats' => [
                'total' => (clone $base)->count(),
                'pending' => $count('Pending'),
                'incomplete' => $count('Incomplete'),
                'processing' => $count('Processing'),
                'shipped' => $count('Shipped'),
                'delivered' => $count('Delivered'),
                'returned' => $count('Returned'),
                'cancelled' => $count('Cancelled'),
                'approved' => (clone $base)->whereNotNull('approved_at')->count(),
                'revenue' => $revenue,
                'profit' => $profit,
                'created_today' => $onDay(clone $base, 'created_at', $todayD)->count(),
                'sent' => $count('Shipped') + $count('Delivered'),
                'sources' => $sources,
                'courier_rows' => $notSent,
            ],
        ]);
    }

    /** Earning Reports are loaded only when asked for (prototype behaviour). */
    public function earnings(Request $request): \Illuminate\Http\JsonResponse
    {
        $biz = (int) ($request->user()?->business_id ?? 0);
        // Months are Dhaka calendar months, like every other money figure:
        // whereYear/whereMonth on the stored UTC instants would put the
        // first six Dhaka hours of each month into the previous month.
        $year = (int) now('Asia/Dhaka')->format('Y');
        $from = Carbon::parse("{$year}-01-01", 'Asia/Dhaka')->startOfDay()->utc();
        $to = Carbon::parse("{$year}-12-31", 'Asia/Dhaka')->endOfDay()->utc();
        $orders = Order::where('business_id', $biz)->where('status', 'Delivered')
            ->whereBetween('created_at', [$from, $to])->with('items')->get();
        $months = collect(range(1, 12))->map(function ($m) use ($orders) {
            $inMonth = $orders->filter(fn ($o) => (int) $o->created_at->copy()->setTimezone('Asia/Dhaka')->format('n') === $m);
            $revenue = (float) $inMonth->sum('grand_total');
            $costs = (float) $inMonth->sum(fn ($o) => $o->items->sum(fn ($i) => (float) $i->cost * $i->qty) + (float) $o->delivery_charge);

            return ['label' => date('M', mktime(0, 0, 0, $m, 1)), 'revenue' => $revenue, 'profit' => $revenue - $costs];
        })->all();

        return response()->json(['year' => $year, 'months' => $months]);
    }
}
