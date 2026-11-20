<?php

namespace App\Http\Controllers;

use App\Models\CallLog;
use App\Models\Customer;
use App\Models\FinanceTransaction;
use App\Models\Order;
use App\Models\OrderSource;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * M15 Reports — every figure is computed live from the shop's own data.
 * P&L reuses FinanceService::figures so Reports and Finance never disagree.
 */
class ReportsController extends Controller
{
    public function __construct(private readonly FinanceService $fin, private readonly SettingsService $settings) {}

    private function biz(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    /** @return array{0:string,1:Carbon,2:Carbon} */
    private function period(Request $request): array
    {
        $preset = (string) $request->query('preset', 'month');
        // Asia/Dhaka, like Finance: the app default tz is UTC, so Carbon::now()
        // ends "this month" a day early in Dhaka terms and P&L disagreed with
        // the Finance dashboard by exactly the boundary day's expenses.
        $now = Carbon::now('Asia/Dhaka');
        $to = $now->copy()->endOfDay();

        return [$preset, match ($preset) {
            'today' => $now->copy()->startOfDay(),
            '7d' => $now->copy()->subDays(6)->startOfDay(),
            default => $now->copy()->startOfMonth(),
        }, $to];
    }

    private function periodWord(string $preset): string
    {
        return match ($preset) { 'today' => 'আজ', '7d' => 'গত 7 দিনে', default => 'এই মাসে' };
    }

    private function pct(int $part, int $whole): int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : 0;
    }

    // ---------- Profit & Loss ----------

    private function plData(int $biz, Carbon $from, Carbon $to): array
    {
        $f = $this->fin->figures($biz, $from, $to);
        $adExpense = round((float) FinanceTransaction::where('business_id', $biz)->where('type', 'expense')
            ->whereNull('voided_at')->whereNull('reversal_of')
            ->whereBetween('txn_date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('category', fn ($q) => $q->where('name', 'Ad'))->sum('amount'), 2);
        // figures() counts live rows only; mirror its rule via the service totals.
        $adExpense = min($adExpense, (float) $f['expense']);
        $otherExpense = round((float) $f['expense'] - $adExpense, 2);
        $deliveredCount = Order::where('business_id', $biz)->where('status', 'Delivered')
            ->whereBetween('updated_at', [$from, $to])->count();

        $rows = [['label' => 'মোট বিক্রি', 'amount' => (float) $f['revenue']]];
        if ((float) $f['otherIncome'] > 0) {
            $rows[] = ['label' => 'অন্য আয়', 'amount' => (float) $f['otherIncome']];
        }
        $rows[] = ['label' => 'পণ্যের খরচ', 'amount' => -(float) $f['productCost']];
        $rows[] = ['label' => 'Delivery খরচ', 'amount' => -(float) $f['courierCharges']];
        $rows[] = ['label' => 'Ad খরচ', 'amount' => -$adExpense];
        $rows[] = ['label' => 'অন্য খরচ (ভাড়া, packaging, SMS)', 'amount' => -$otherExpense];
        if ((float) $f['rtoLoss'] > 0) {
            $rows[] = ['label' => 'Return ক্ষতি', 'amount' => -(float) $f['rtoLoss']];
        }
        $rows[] = ['label' => 'নিট লাভ', 'amount' => (float) $f['profit'], 'total' => true];

        $estimateEnabled = (bool) $this->settings->get("reports.{$biz}.estimate_enabled", false);
        $estimate = null;
        if ($estimateEnabled) {
            $resolved = Order::where('business_id', $biz)->whereIn('status', ['Delivered', 'Returned'])
                ->whereBetween('updated_at', [$from, $to]);
            $d = (clone $resolved)->where('status', 'Delivered')->count();
            $r = (clone $resolved)->where('status', 'Returned')->count();
            $rate = ($d + $r) > 0 ? $d / ($d + $r) : 0;
            $pending = Order::where('business_id', $biz)->whereIn('status', ['Processing', 'Shipped'])
                ->whereBetween('created_at', [$from, $to])->with('items')->get();
            $potential = $pending->sum(fn (Order $o) => (float) $o->grand_total
                - $o->items->sum(fn ($i) => (float) $i->cost * (int) $i->qty) - (float) $o->delivery_charge);
            $estimate = ['rate' => round($rate * 100, 1), 'orders' => $pending->count(),
                'amount' => round($potential * $rate, 2)];
        }

        return [
            'kpis' => [
                'sales' => (float) $f['revenue'], 'profit' => (float) $f['profit'],
                'margin' => (float) $f['revenue'] > 0 ? (int) round((float) $f['profit'] / (float) $f['revenue'] * 100) : 0,
                'per_order' => $deliveredCount > 0 ? round((float) $f['profit'] / $deliveredCount, 2) : 0,
            ],
            'rows' => $rows,
            'estimate_enabled' => $estimateEnabled,
            'estimate' => $estimate,
        ];
    }

    public function pl(Request $request): InertiaResponse
    {
        [$preset, $from, $to] = $this->period($request);

        return Inertia::render('Reports/Pl', ['preset' => $preset] + $this->plData($this->biz($request), $from, $to));
    }

    public function saveEstimate(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->settings->set("reports.{$this->biz($request)}.estimate_enabled", (bool) $data['enabled']);

        return back()->with('success', 'P&L setting saved');
    }

    // ---------- Order Report ----------

    private function orderData(int $biz, Carbon $from, Carbon $to): array
    {
        $orders = Order::where('business_id', $biz)->whereBetween('created_at', [$from, $to])->get();
        $labels = OrderSource::where('business_id', $biz)->pluck('label', 'code');
        $rows = $orders->groupBy('source')->map(function ($g, $code) use ($labels) {
            $total = $g->count();
            $del = $g->where('status', 'Delivered')->count();
            $ret = $g->where('status', 'Returned')->count();

            return ['source' => $labels[$code] ?? $code, 'orders' => $total,
                'delivered_pct' => $this->pct($del, $total), 'returned_pct' => $this->pct($ret, $total),
                'sales' => round($g->whereNotIn('status', ['Cancelled'])->sum('grand_total'), 2)];
        })->sortByDesc('orders')->values()->all();

        return [
            'kpis' => ['total' => $orders->count(),
                'delivered_pct' => $this->pct($orders->where('status', 'Delivered')->count(), $orders->count()),
                'returned_pct' => $this->pct($orders->where('status', 'Returned')->count(), $orders->count()),
                'cancelled_pct' => $this->pct($orders->where('status', 'Cancelled')->count(), $orders->count())],
            'rows' => $rows,
        ];
    }

    public function order(Request $request): InertiaResponse
    {
        [$preset, $from, $to] = $this->period($request);

        return Inertia::render('Reports/Order', ['preset' => $preset] + $this->orderData($this->biz($request), $from, $to));
    }

    // ---------- Courier Report (performance only — money lives in Delivery → Courier Ledger) ----------

    private function courierData(int $biz, Carbon $from, Carbon $to): array
    {
        $orders = Order::where('business_id', $biz)->whereNotNull('delivery_method')
            ->whereIn('status', ['Shipped', 'Delivered', 'Returned'])
            ->whereBetween('updated_at', [$from, $to])->get();
        $hist = OrderStatusHistory::whereIn('order_id', $orders->pluck('id'))
            ->whereIn('to_status', ['Shipped', 'Delivered'])->get()->groupBy('order_id');

        $rows = $orders->groupBy(fn (Order $o) => trim(explode('—', $o->delivery_method)[0]))
            ->map(function ($g, $name) use ($hist) {
                $sent = $g->count();
                $del = $g->where('status', 'Delivered')->count();
                $ret = $g->where('status', 'Returned')->count();
                $days = [];
                foreach ($g->where('status', 'Delivered') as $o) {
                    $h = $hist->get($o->id);
                    if (! $h) {
                        continue;
                    }
                    $ship = $h->firstWhere('to_status', 'Shipped')?->created_at;
                    $done = $h->firstWhere('to_status', 'Delivered')?->created_at;
                    if ($ship && $done) {
                        $days[] = $ship->diffInMinutes($done) / 1440;
                    }
                }

                return ['courier' => $name, 'sent' => $sent,
                    'delivered_pct' => $this->pct($del, $sent), 'return_pct' => $this->pct($ret, $sent),
                    'avg_days' => $days ? round(array_sum($days) / count($days), 1) : null];
            })->sortByDesc('sent')->values()->all();

        $best = collect($rows)->filter(fn ($r) => $r['sent'] >= 3)
            ->sortBy([['delivered_pct', 'desc'], ['avg_days', 'asc']])->first();

        return ['rows' => $rows, 'best' => $best['courier'] ?? null];
    }

    public function courier(Request $request): InertiaResponse
    {
        [$preset, $from, $to] = $this->period($request);

        return Inertia::render('Reports/Courier', ['preset' => $preset, 'word' => $this->periodWord($preset)]
            + $this->courierData($this->biz($request), $from, $to));
    }

    // ---------- Product Report ----------

    private function productData(int $biz, Carbon $from, Carbon $to): array
    {
        $orders = Order::where('business_id', $biz)->where('status', 'Delivered')
            ->whereBetween('updated_at', [$from, $to])->with('items')->get();
        $byProduct = [];
        foreach ($orders as $o) {
            foreach ($o->items as $i) {
                $k = $i->product_id ? 'p'.$i->product_id : 'n'.$i->name;
                $byProduct[$k] ??= ['product' => $i->name, 'qty' => 0, 'revenue' => 0.0, 'profit' => 0.0];
                $byProduct[$k]['qty'] += (int) $i->qty;
                $byProduct[$k]['revenue'] += (float) $i->price * (int) $i->qty;
                $byProduct[$k]['profit'] += ((float) $i->price - (float) $i->cost) * (int) $i->qty;
            }
        }
        $rows = collect($byProduct)->map(function ($r) {
            $r['revenue'] = round($r['revenue'], 2);
            $r['profit'] = round($r['profit'], 2);
            $r['margin'] = $r['revenue'] > 0 ? (int) round($r['profit'] / $r['revenue'] * 100) : 0;

            return $r;
        })->sortByDesc('revenue')->values()->all();
        $worst = collect($rows)->filter(fn ($r) => $r['qty'] > 0)->sortBy('margin')->first();

        return ['rows' => $rows, 'worst' => $worst && $worst['margin'] < 20 ? $worst['product'] : null];
    }

    public function product(Request $request): InertiaResponse
    {
        [$preset, $from, $to] = $this->period($request);

        return Inertia::render('Reports/Product', ['preset' => $preset] + $this->productData($this->biz($request), $from, $to));
    }

    // ---------- Employee Report ----------

    private function employeeData(int $biz, Carbon $from, Carbon $to): array
    {
        $users = User::where('business_id', $biz)->orderBy('name')->get();
        $orders = Order::where('business_id', $biz)->whereNotNull('processed_by')
            ->whereBetween('updated_at', [$from, $to])->get()->groupBy('processed_by');
        $rows = $users->map(function (User $u) use ($orders) {
            $g = $orders->get($u->id, collect());
            $n = $g->count();

            return ['name' => $u->name, 'processed' => $n,
                'delivered_pct' => $n ? $this->pct($g->where('status', 'Delivered')->count(), $n) : null,
                'return_pct' => $n ? $this->pct($g->where('status', 'Returned')->count(), $n) : null];
        })->sortByDesc('processed')->values()->all();

        return ['rows' => $rows];
    }

    public function employee(Request $request): InertiaResponse
    {
        [$preset, $from, $to] = $this->period($request);

        return Inertia::render('Reports/Employee', ['preset' => $preset] + $this->employeeData($this->biz($request), $from, $to));
    }

    // ---------- Call Center Report ----------

    private function callData(int $biz, Carbon $from, Carbon $to): array
    {
        $logs = CallLog::where('business_id', $biz)->whereBetween('created_at', [$from, $to])->get();
        $total = $logs->count();
        $avg = $total ? $logs->avg('minutes') : 0;
        $avgText = sprintf('%d:%02d', (int) $avg, (int) round(($avg - (int) $avg) * 60));
        $linked = $logs->whereNotNull('order_id');
        $bestSlot = null;
        if ($linked->isNotEmpty()) {
            $byHour = $linked->groupBy(fn ($l) => (int) $l->created_at->format('H'))
                ->map->count()->sortDesc();
            $h = (int) $byHour->keys()->first();
            $bestSlot = sprintf('%02d:00–%02d:00', $h, ($h + 2) % 24);
        }

        return ['kpis' => ['total' => $total,
            'answered_pct' => $this->pct($logs->where('outcome', 'verified')->count(), $total),
            'orders' => $linked->count(), 'avg' => $avgText], 'best_slot' => $bestSlot];
    }

    public function call(Request $request): InertiaResponse
    {
        [$preset, $from, $to] = $this->period($request);

        return Inertia::render('Reports/Call', ['preset' => $preset] + $this->callData($this->biz($request), $from, $to));
    }

    // ---------- Customer Report ----------

    private function customerData(int $biz, Carbon $from, Carbon $to): array
    {
        $orders = Order::where('business_id', $biz)->whereNotIn('status', ['Cancelled'])->get();
        $byPhone = $orders->groupBy('customer_phone');
        $withOrders = $byPhone->count();
        $repeat = $byPhone->filter(fn ($g) => $g->count() >= 2)->count();
        $periodOrders = $orders->whereBetween('created_at', [$from, $to]);
        $top = $periodOrders->groupBy('customer_phone')->map(function ($g) {
            return ['name' => $g->first()->customer_name, 'orders' => $g->count(),
                'spent' => round($g->sum('grand_total'), 2)];
        })->sortByDesc('spent')->take(5)->values()->all();

        return ['kpis' => [
            'total' => Customer::where('business_id', $biz)->count(),
            'new' => Customer::where('business_id', $biz)->whereBetween('created_at', [$from, $to])->count(),
            'repeat_pct' => $this->pct($repeat, max($withOrders, 1)),
            'avg_value' => $periodOrders->count() ? round($periodOrders->avg('grand_total'), 2) : 0,
        ], 'rows' => $top];
    }

    public function customer(Request $request): InertiaResponse
    {
        [$preset, $from, $to] = $this->period($request);

        return Inertia::render('Reports/Customer', ['preset' => $preset] + $this->customerData($this->biz($request), $from, $to));
    }

    // ---------- My Limits (parked) ----------

    public function limits(Request $request): InertiaResponse
    {
        return Inertia::render('Reports/Limits');
    }

    // ---------- CSV export (the Print / Download button) ----------

    public function export(Request $request, string $key): \Symfony\Component\HttpFoundation\Response
    {
        $biz = $this->biz($request);
        [, $from, $to] = $this->period($request);
        $lines = [];
        switch ($key) {
            case 'pl':
                $lines[] = ['খাত', 'টাকা'];
                foreach ($this->plData($biz, $from, $to)['rows'] as $r) {
                    $lines[] = [$r['label'], $r['amount']];
                }
                break;
            case 'order':
                $lines[] = ['উৎস', 'Order', 'Delivered %', 'Returned %', 'বিক্রি'];
                foreach ($this->orderData($biz, $from, $to)['rows'] as $r) {
                    $lines[] = [$r['source'], $r['orders'], $r['delivered_pct'], $r['returned_pct'], $r['sales']];
                }
                break;
            case 'courier':
                $lines[] = ['Courier', 'পাঠানো', 'Delivered %', 'Return %', 'গড় সময় (দিন)'];
                foreach ($this->courierData($biz, $from, $to)['rows'] as $r) {
                    $lines[] = [$r['courier'], $r['sent'], $r['delivered_pct'], $r['return_pct'], $r['avg_days'] ?? ''];
                }
                break;
            case 'product':
                $lines[] = ['Product', 'বিক্রি হয়েছে', 'আয়', 'লাভ', 'Margin %'];
                foreach ($this->productData($biz, $from, $to)['rows'] as $r) {
                    $lines[] = [$r['product'], $r['qty'], $r['revenue'], $r['profit'], $r['margin']];
                }
                break;
            case 'employee':
                $lines[] = ['নাম', 'Order process করেছে', 'Delivered %', 'Return %'];
                foreach ($this->employeeData($biz, $from, $to)['rows'] as $r) {
                    $lines[] = [$r['name'], $r['processed'], $r['delivered_pct'] ?? '', $r['return_pct'] ?? ''];
                }
                break;
            case 'call':
                $k = $this->callData($biz, $from, $to)['kpis'];
                $lines = [['মোট call', $k['total']], ['Call ধরা হয়েছে %', $k['answered_pct']], ['Call থেকে order', $k['orders']], ['গড় কথার সময়', $k['avg']]];
                break;
            case 'customer':
                $lines[] = ['সেরা customer', 'Order', 'মোট কেনা'];
                foreach ($this->customerData($biz, $from, $to)['rows'] as $r) {
                    $lines[] = [$r['name'], $r['orders'], $r['spent']];
                }
                break;
            default:
                abort(404);
        }
        $csv = collect($lines)->map(fn ($l) => collect($l)->map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"')->implode(','))->implode("\n");

        return Response::make("\xEF\xBB\xBF".$csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"report-{$key}.csv\"",
        ]);
    }
}
