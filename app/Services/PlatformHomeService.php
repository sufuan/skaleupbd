<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessWallet;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\SellerPayment;
use App\Models\SellerSubscription;
use App\Models\TokenLedger;
use App\Support\DhakaTime;
use Carbon\Carbon;

/**
 * Platform Home (ধাপ ৩): the platform admin's dashboard face. ONE
 * home for the platform aggregates - PlatformController@dashboard
 * (/admin) and DashboardController@index (/dashboard in platform
 * context) both render exactly this data, so the two URLs can never
 * drift into different definitions.
 *
 * Every number is an aggregate over shops; none of them reads
 * end-customer records. Definitions (prep map section 2):
 *  - total sales = order grand_total summed with Cancelled excluded
 *    (the platform's existing sellerRow definition, NOT the shop
 *    dashboard's Delivered-only one);
 *  - package income (the PACKAGE INCOME / INCOME THIS MONTH cards) =
 *    package payments only, under the same definition as the seller
 *    detail purchase history: pack purchases, the one-time onboarding
 *    fee and their (negative) refunds. Free-form manual payments are
 *    recorded money, but they are not package income and never count
 *    on those cards;
 *  - points sold = token_ledger rows of kind "purchase" (credits,
 *    not money).
 * "Today" and "this month" figures use Dhaka calendar bounds, like
 * every other money figure in the app.
 */
class PlatformHomeService
{
    /**
     * Package payments only — the exact definition the seller detail
     * page's purchase history already uses (PlatformController@show):
     * pack purchases, the one-time onboarding fee, and their refunds.
     */
    private function packagePayments(): \Illuminate\Database\Eloquent\Builder
    {
        return SellerPayment::query()->where(fn ($q) => $q->where('note', 'like', '%pack purchase%')
            ->orWhere('note', 'like', 'Onboarding fee%')
            ->orWhere('note', 'like', 'Refund — %'));
    }

    private function currentSub(int $businessId): ?SellerSubscription
    {
        return SellerSubscription::where('business_id', $businessId)->with('package')
            ->orderByDesc('id')->first();
    }

    /** One row of the per-shop health list (moved verbatim from PlatformController). */
    public function sellerRow(Business $b): array
    {
        $sub = $this->currentSub($b->id);
        $orders = Order::where('business_id', $b->id);
        $live = $sub && $sub->isLive();
        $wallet = BusinessWallet::where('business_id', $b->id)->first();

        // M19 truth: the points wallet decides the pack display. The
        // legacy subscription only fills the gap for a shop that has a
        // subscription but never bought points (pre-M19 records).
        $packName = $sub?->package?->name;
        $status = $sub ? ($live ? 'active' : ($sub->status === 'cancelled' ? 'cancelled' : 'expired')) : 'none';
        $expiresAt = $sub?->expires_at?->format('d M Y');
        $daysLeft = $sub ? (int) today()->diffInDays($sub->expires_at, false) : null;
        if ($wallet && $wallet->first_purchase_at) {
            $until = $wallet->cycle_end ?? $wallet->wallet_expires_at;
            $packName = $wallet->packName() ?? $packName;
            $status = ($until && $until->isPast()) ? 'expired' : 'active';
            $expiresAt = $until?->format('d M Y');
            $daysLeft = $until ? (int) today()->diffInDays($until, false) : null;
        }
        // Granted points (Hunt W W11): a shop the platform credited by
        // hand has a wallet with a usable balance but no pack purchase,
        // so neither branch above fires and the shop read "No package"
        // while actively trading. Active means: the shop can take
        // orders - a current pack (handled above) OR a positive points
        // balance.
        if ($status === 'none' && $wallet && (int) $wallet->balance > 0) {
            $status = 'active';
        }

        return [
            'id' => $b->id,
            'name' => $b->name,
            'public_id' => $b->public_id,
            'approval_status' => $b->status ?: 'approved',
            'status_note' => $b->status_note,
            'approved_at' => DhakaTime::fmt($b->approved_at, 'd M Y, h:i A'),
            'owner_name' => $b->owner_name,
            'phone' => $b->phone,
            'email' => $b->email,
            'address' => $b->address,
            'joined' => DhakaTime::fmt($b->created_at, 'd M Y'),
            'package' => $packName,
            'package_price' => $sub ? (float) $sub->amount : null,
            'status' => $status,
            'expires_at' => $expiresAt,
            'days_left' => $daysLeft,
            'paid_total' => (float) SellerPayment::where('business_id', $b->id)->sum('amount'),
            'token_balance' => $wallet ? (int) $wallet->balance : 0,
            'token_pack' => $wallet?->packName(),
            'token_until' => ($wallet?->cycle_end ?? $wallet?->wallet_expires_at)?->format('d M Y'),
            'orders' => (clone $orders)->count(),
            // Booked order value, cancelled excluded — deliberately NOT the
            // sellers' Delivered-only revenue, so the platform UI labels it
            // "Booked order value", never "Sales"/"Revenue" (one number, one
            // definition, one name). Delivered-only made every active seller
            // read ৳0 until parcels completed.
            'revenue' => (float) (clone $orders)->whereNotIn('status', ['Cancelled'])->sum('grand_total'),
            'customers' => Customer::where('business_id', $b->id)->count(),
        ];
    }

    /** The full prop set for the Platform/Dashboard page. */
    public function dashboardData(): array
    {
        $sellers = Business::orderBy('name')->get()->map(fn ($b) => $this->sellerRow($b));
        $payments = SellerPayment::with('business')->latest('paid_at')->limit(10)->get();

        // Dhaka calendar month bounds (the old whereYear/whereMonth on
        // stored UTC instants mis-bucketed the first six Dhaka hours
        // of each month - same warning as DashboardController@earnings).
        $monthStart = Carbon::now(DhakaTime::TZ)->startOfMonth()->utc();
        $monthEnd = Carbon::now(DhakaTime::TZ)->endOfMonth()->utc();
        $monthIncome = (float) $this->packagePayments()->whereBetween('paid_at', [$monthStart, $monthEnd])->sum('amount');
        [$todayFrom, $todayTo] = DhakaTime::dayBoundsUtc(DhakaTime::today());

        return [
            'stats' => [
                'sellers' => $sellers->count(),
                'active' => $sellers->where('status', 'active')->count(),
                'expired' => $sellers->where('status', 'expired')->count(),
                'income_total' => (float) $this->packagePayments()->sum('amount'),
                'income_month' => $monthIncome,
                'expiring_soon' => $sellers->filter(fn ($s) => $s['status'] === 'active' && $s['days_left'] !== null && $s['days_left'] <= 7)->count(),
                // ধাপ ৩ additions (prep map section 2, items 2-6).
                'pending_approvals' => Business::where('status', 'pending')->count(),
                'new_today' => Business::whereBetween('created_at', [$todayFrom, $todayTo])->count(),
                'orders_total' => Order::count(),
                'sales_total' => (float) Order::whereNotIn('status', ['Cancelled'])->sum('grand_total'),
                'points_sold' => (int) TokenLedger::where('kind', 'purchase')->sum('amount'),
            ],
            'sellers' => $sellers->all(),
            'recentPayments' => $payments->map(fn ($p) => [
                'id' => $p->id, 'seller' => $p->business?->name, 'amount' => (float) $p->amount,
                'method' => $p->method, 'note' => $p->note, 'when' => DhakaTime::fmt($p->paid_at, 'd M Y, h:i A'),
            ])->all(),
            'topSellers' => $sellers->sortByDesc('revenue')->take(5)->values()->all(),
            'expiring' => $sellers->filter(fn ($s) => $s['status'] === 'active' && $s['days_left'] !== null && $s['days_left'] <= 7)->values()->all(),
            'packages' => PointPack::where('is_active', true)->orderBy('sort')->get()->map(fn ($p) => [
                'name' => $p->name, 'price' => (float) $p->price_bdt,
                'sellers' => BusinessWallet::where('pack_id', $p->id)->count(),
            ])->all(),
        ];
    }
}
