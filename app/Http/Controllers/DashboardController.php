<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\DashboardStatsService;
use App\Support\DhakaTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private DashboardStatsService $stats) {}

    /**
     * Every number comes from DashboardStatsService (one definition each).
     * The selected Dhaka day (?day=) anchors every date-sensitive block:
     * the My Dashboard day cohort, the 30-day windows of Overview /
     * Products / Agent Performance (ending on that day), the Marketing
     * heatmap day and the Activity day column. Decision and Operations are
     * current-state views (open work right now) and ignore the day.
     * The counting basis (?basis= approved|created|courier) applies to the
     * My Dashboard KPI row, Orders by Source and Top Selling Products.
     */
    public function index(Request $request): Response
    {
        // ধাপ ৩: one /dashboard, two faces. A platform admin who is
        // not viewing a shop gets Platform Home (the platform
        // aggregates, one definition in PlatformHomeService - the
        // same data /admin renders). Sellers, and an admin inside a
        // shop through view-as-seller, get the shop face for the
        // resolved shop.
        if (\App\Support\ShopContext::isPlatformContext($request)) {
            return Inertia::render('Platform/Dashboard', app(\App\Services\PlatformHomeService::class)->dashboardData());
        }

        $biz = \App\Support\ShopContext::id($request);
        $dayStr = $request->query('day');
        $dayStr = is_string($dayStr) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayStr) ? $dayStr : DhakaTime::today();
        $basis = array_key_exists((string) $request->query('basis'), DashboardStatsService::BASES)
            ? (string) $request->query('basis') : 'approved';

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
            'my' => $this->stats->myDashboard($biz, $dayStr, $basis),
            'ov' => $this->stats->overview($biz, $dayStr),
            'dc' => $this->stats->decision($biz),
            'ops' => $this->stats->operations($biz),
            'prod' => $this->stats->products($biz, $dayStr),
            'mkt' => $this->stats->marketing($biz, $dayStr),
            'team' => $this->stats->team($biz, $dayStr),
            'act' => $this->stats->activity($biz, $dayStr),
        ]);
    }

    /** Earning Reports are loaded only when asked for (prototype behaviour). */
    public function earnings(Request $request): \Illuminate\Http\JsonResponse
    {
        $biz = \App\Support\ShopContext::id($request);
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
