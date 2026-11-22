<?php

namespace App\Http\Controllers;

use App\Models\DailyClosing;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Order;
use App\Services\FinanceService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class FinanceController extends Controller
{
    public function __construct(private FinanceService $fin)
    {
    }

    private function biz(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    private function txnRow(FinanceTransaction $t): array
    {
        return [
            'id' => $t->id,
            'date' => $t->txn_date->format('d M'),
            'date_full' => $t->txn_date->toDateString(),
            'note' => $t->note ?: $this->typeLabel($t),
            'type' => $t->type,
            'direction' => $t->direction,
            'amount' => (float) $t->amount,
            'account' => $t->account?->name,
            'account_id' => $t->account_id,
            'category' => $t->category?->name,
            'category_id' => $t->category_id,
            'source' => $t->source,
            'note_raw' => $t->note,
            'voided' => $t->voided_at !== null,
            'is_reversal' => $t->reversal_of !== null,
            'by' => $t->creator?->name,
        ];
    }

    private function typeLabel(FinanceTransaction $t): string
    {
        return match ($t->type) {
            'income' => 'আয়', 'expense' => 'খরচ', 'transfer' => 'Transfer',
            'owner_in' => 'মালিকের জমা', 'owner_out' => 'মালিকের তোলা',
            'advance' => 'Order advance', 'courier_payout' => 'Courier payout',
            'deposit' => 'জমা', 'withdrawal' => 'তোলা', default => $t->type,
        };
    }

    private function accountsPayload(int $biz): array
    {
        return FinanceAccount::where('business_id', $biz)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (FinanceAccount $a) => [
                'id' => $a->id, 'name' => $a->name, 'type' => $a->type,
                'opening' => (float) $a->opening_balance, 'active' => (bool) $a->active,
                'balance' => $this->fin->accountBalance($a),
            ])->all();
    }

    private function categoriesPayload(int $biz): array
    {
        return FinanceCategory::where('business_id', $biz)->orderBy('kind')->orderBy('sort')->orderBy('id')->get()
            ->map(fn (FinanceCategory $c) => ['id' => $c->id, 'name' => $c->name, 'kind' => $c->kind, 'is_system' => (bool) $c->is_system])->all();
    }

    public function dashboard(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $this->fin->ensureCategories($biz);
        $user = $request->user();
        $canSee = $this->fin->canSeeBalances($user);
        $accounts = $this->accountsPayload($biz);
        $setupDone = count($accounts) > 0;

        $period = $request->query('period', 'month');
        $today = now('Asia/Dhaka');
        [$from, $to] = match ($period) {
            'today' => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
            'week' => [$today->copy()->startOfWeek(), $today->copy()->endOfDay()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            default => [$today->copy()->startOfMonth(), $today->copy()->endOfDay()],
        };

        $props = [
            'setupDone' => $setupDone,
            'canSee' => $canSee,
            'period' => $period,
            'accounts' => $canSee ? $accounts : [],
            'categories' => $this->categoriesPayload($biz),
            'dailyClosingOn' => (bool) $this->fin->setting($biz, 'daily_closing', false),
            'closedThrough' => $this->fin->closedThrough($biz),
        ];
        if (! $setupDone || ! $canSee) {
            $props['recent'] = FinanceTransaction::where('business_id', $biz)->with(['account', 'category', 'creator'])
                ->orderByDesc('txn_date')->orderByDesc('id')->limit(10)->get()->map(fn ($t) => $this->txnRow($t))->all();
            return Inertia::render('Finance/Dashboard', $props);
        }

        $figures = $this->fin->figures($biz, $from, $to);
        $bridge = $this->fin->bridge($biz);
        $dues = $this->fin->dues($biz);

        // Weekly payout check: delivered more than 7 days ago and still not fully settled.
        $settlements = app(\App\Services\SettlementService::class);
        $stale = Order::where('business_id', $biz)->where('status', 'Delivered')
            ->where('updated_at', '<', $today->copy()->subDays(7))->get()
            ->filter(fn (Order $o) => $settlements->settledNet($o) < $settlements->expectedNet($o) - 0.01);
        $staleAmount = round($stale->sum(fn (Order $o) => $settlements->expectedNet($o) - $settlements->settledNet($o)), 2);

        // Today's closing expectation for the default cash account.
        $cash = FinanceAccount::where('business_id', $biz)->where('type', 'cash')->where('active', true)->first();
        $closing = null;
        if ($cash) {
            $closing = DailyClosing::where('business_id', $biz)->where('account_id', $cash->id)
                ->where('date', $today->toDateString())->first();
        }

        $props += [
            'figures' => $figures,
            'bridge' => $bridge,
            'dues' => $dues,
            'totalCash' => $this->fin->totalCash($biz),
            'inventoryValue' => round((float) \App\Models\Product::where('business_id', $biz)
                ->selectRaw('SUM(stock_qty * cost) as v')->value('v'), 2),
            // M5 hook: what the shop owes its suppliers (was hidden until
            // the Purchase module existed) — one figure, from M5's tables.
            'supplierDue' => \Illuminate\Support\Facades\Schema::hasTable('purchases')
                ? app(\App\Services\PurchaseService::class)->totalDue($biz)
                : 0,
            'stalePayouts' => ['count' => $stale->count(), 'amount' => $staleAmount],
            'cashAccount' => $cash ? ['id' => $cash->id, 'name' => $cash->name, 'balance' => $this->fin->accountBalance($cash)] : null,
            'closingToday' => $closing ? ['counted' => (float) $closing->counted, 'difference' => (float) $closing->difference] : null,
            'recent' => FinanceTransaction::where('business_id', $biz)->with(['account', 'category', 'creator'])
                ->orderByDesc('txn_date')->orderByDesc('id')->limit(10)->get()->map(fn ($t) => $this->txnRow($t))->all(),
        ];
        return Inertia::render('Finance/Dashboard', $props);
    }

    public function accounts(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $this->fin->ensureCategories($biz);
        $today = now('Asia/Dhaka')->toDateString();
        $accounts = FinanceAccount::where('business_id', $biz)->orderBy('sort')->orderBy('id')->get()->map(function (FinanceAccount $a) use ($today) {
            return [
                'id' => $a->id, 'name' => $a->name, 'type' => $a->type, 'active' => (bool) $a->active,
                'balance' => $this->fin->accountBalance($a),
                'today_in' => round((float) FinanceTransaction::where('account_id', $a->id)->where('direction', 'in')->where('txn_date', $today)->whereNull('voided_at')->whereNull('reversal_of')->sum('amount'), 2),
                'today_out' => round((float) FinanceTransaction::where('account_id', $a->id)->where('direction', 'out')->where('txn_date', $today)->whereNull('voided_at')->whereNull('reversal_of')->sum('amount'), 2),
            ];
        })->all();

        return Inertia::render('Finance/Accounts', [
            'accounts' => $accounts,
            'canSee' => $this->fin->canSeeBalances($request->user()),
            'categories' => $this->categoriesPayload($biz),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'type' => 'required|in:cash,bank,mobile',
            'opening_balance' => 'nullable|numeric|min:0',
        ]);
        FinanceAccount::create([
            'business_id' => $biz, 'name' => $data['name'], 'type' => $data['type'],
            'opening_balance' => $data['opening_balance'] ?? 0,
        ]);
        return back()->with('success', 'Account যোগ হয়েছে।');
    }

    public function updateAccount(Request $request, FinanceAccount $account): RedirectResponse
    {
        abort_unless($account->business_id === $this->biz($request), 404);
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'type' => 'required|in:cash,bank,mobile',
            'active' => 'sometimes|boolean',
        ]);
        $account->update($data);
        return back()->with('success', 'Account বদলানো হয়েছে।');
    }

    /** The one entry sheet: income / expense / deposit / withdrawal / transfer / owner / courier payout. */
    public function storeTransaction(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $data = $request->validate([
            'kind' => 'required|in:income,expense,deposit,withdrawal,transfer,owner_in,owner_out,courier_payout',
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|integer',
            'to_account_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'note' => 'nullable|string|max:300',
            'txn_date' => 'nullable|date',
        ]);
        $account = FinanceAccount::where('business_id', $biz)->findOrFail($data['account_id']);
        $date = $data['txn_date'] ?? now('Asia/Dhaka')->toDateString();

        try {
            if ($data['kind'] === 'transfer') {
                $to = FinanceAccount::where('business_id', $biz)->findOrFail($data['to_account_id']);
                if ($to->id === $account->id) {
                    return back()->with('error', 'একই account-এ transfer হয় না।');
                }
                $this->fin->transfer($biz, $account->id, $to->id, (float) $data['amount'], $data['note'] ?? null, $request->user(), $date);
            } else {
                [$type, $direction] = match ($data['kind']) {
                    'income' => ['income', 'in'], 'expense' => ['expense', 'out'],
                    'deposit' => ['deposit', 'in'], 'withdrawal' => ['withdrawal', 'out'],
                    'owner_in' => ['owner_in', 'in'], 'owner_out' => ['owner_out', 'out'],
                    'courier_payout' => ['courier_payout', 'in'],
                };
                $this->fin->post($biz, [
                    'account_id' => $account->id, 'direction' => $direction, 'type' => $type,
                    'amount' => $data['amount'], 'category_id' => $data['category_id'] ?? null,
                    'note' => $data['note'] ?? null, 'txn_date' => $date,
                ], $request->user());
            }
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'লেনদেন যোগ হয়েছে।');
    }

    public function voidTransaction(Request $request, FinanceTransaction $transaction): RedirectResponse
    {
        abort_unless($transaction->business_id === $this->biz($request), 404);
        if ($transaction->voided_at) {
            return back()->with('error', 'এটা আগেই বাতিল করা।');
        }
        if ($transaction->reversal_of) {
            return back()->with('error', 'উল্টো entry বাতিল করা যায় না।');
        }
        if ($this->fin->monthLocked($transaction->business_id, $transaction->txn_date->toDateString())) {
            return back()->with('error', 'এই মাস বন্ধ — বাতিল করা যাবে না।');
        }
        $this->fin->void($transaction, $request->user());
        return back()->with('success', 'Entry বাতিল হয়েছে — উল্টো entry দিয়ে হিসাব ঠিক রাখা হয়েছে।');
    }

    /** In-place edit of a single income/expense entry (amount, date, category, note). */
    public function updateTransaction(Request $request, FinanceTransaction $transaction): RedirectResponse
    {
        abort_unless($transaction->business_id === $this->biz($request), 404);
        if ($transaction->voided_at) {
            return back()->with('error', 'বাতিল করা entry বদলানো যায় না।');
        }
        if ($transaction->reversal_of !== null) {
            return back()->with('error', 'উল্টো entry বদলানো যায় না।');
        }
        if ($transaction->transfer_pair_id !== null) {
            return back()->with('error', 'Transfer entry সরাসরি বদলানো যায় না।');
        }
        if (! in_array($transaction->type, ['income', 'expense'], true)) {
            return back()->with('error', 'এই ধরনের entry এখান থেকে বদলানো যায় না।');
        }
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'txn_date' => 'required|date',
            'category_id' => 'nullable|integer',
            'note' => 'nullable|string|max:300',
        ]);
        if ($this->fin->monthLocked($transaction->business_id, $transaction->txn_date->toDateString())
            || $this->fin->monthLocked($transaction->business_id, $data['txn_date'])) {
            return back()->with('error', 'এই মাস বন্ধ — বদলানো যাবে না।');
        }
        $categoryId = null;
        if (! empty($data['category_id'])) {
            $cat = FinanceCategory::where('business_id', $transaction->business_id)
                ->where('kind', $transaction->type)->find((int) $data['category_id']);
            if (! $cat) {
                return back()->with('error', 'Category ঠিক নেই।');
            }
            $categoryId = $cat->id;
        }
        $transaction->update([
            'amount' => $data['amount'],
            'txn_date' => $data['txn_date'],
            'category_id' => $categoryId,
            'note' => $data['note'] ?? null,
        ]);

        return back()->with('success', 'Entry বদলানো হয়েছে।');
    }

    public function incomeExpense(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $this->fin->ensureCategories($biz);
        $tab = $request->query('tab', 'overview');
        $catFilter = $request->query('category');
        $today = now('Asia/Dhaka');
        $from = $today->copy()->startOfMonth()->toDateString();
        $to = $today->toDateString();

        // whereDate, not whereBetween on the raw column: txn_date can carry
        // a time part in storage, and a plain string between then drops the
        // month's boundary days (the "No entries this month" bug).
        $base = FinanceTransaction::where('business_id', $biz)->whereIn('type', ['income', 'expense'])
            ->whereNull('voided_at')->whereNull('reversal_of')
            ->whereDate('txn_date', '>=', $from)->whereDate('txn_date', '<=', $to);
        $byCategory = (clone $base)->with('category')->get()
            ->groupBy(fn ($t) => $t->category?->name ?? '—')
            ->map(fn ($rows, $name) => [
                'name' => $name,
                'income' => round($rows->where('type', 'income')->sum('amount'), 2),
                'expense' => round($rows->where('type', 'expense')->sum('amount'), 2),
            ])->values()->all();

        // The list must cover the SAME month as the totals above it — it
        // silently listed all time, so the page disagreed with itself.
        $list = FinanceTransaction::where('business_id', $biz)->whereIn('type', ['income', 'expense'])
            ->whereDate('txn_date', '>=', $from)->whereDate('txn_date', '<=', $to)
            ->with(['account', 'category', 'creator'])
            ->when($tab === 'expense', fn ($q) => $q->where('type', 'expense'))
            ->when($tab === 'income', fn ($q) => $q->where('type', 'income'))
            ->when($catFilter, fn ($q) => $q->where('category_id', (int) $catFilter))
            ->orderByDesc('txn_date')->orderByDesc('id')->limit(200)->get()
            ->map(fn ($t) => $this->txnRow($t))->all();

        return Inertia::render('Finance/IncomeExpense', [
            'tab' => $tab,
            'categoryFilter' => $catFilter ? (int) $catFilter : null,
            'byCategory' => $byCategory,
            'list' => $list,
            'totalIncome' => round((float) (clone $base)->where('type', 'income')->sum('amount'), 2),
            'totalExpense' => round((float) (clone $base)->where('type', 'expense')->sum('amount'), 2),
            'accounts' => $this->accountsPayload($biz),
            'categories' => $this->categoriesPayload($biz),
            'canSee' => $this->fin->canSeeBalances($request->user()),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $data = $request->validate(['name' => 'required|string|max:60', 'kind' => 'required|in:income,expense']);
        FinanceCategory::firstOrCreate(['business_id' => $biz, 'name' => $data['name'], 'kind' => $data['kind']]);
        return back()->with('success', 'Category যোগ হয়েছে।');
    }

    public function updateCategory(Request $request, FinanceCategory $category): RedirectResponse
    {
        abort_unless($category->business_id === $this->biz($request), 404);
        $data = $request->validate(['name' => 'required|string|max:60']);
        $category->update(['name' => $data['name']]);
        return back()->with('success', 'Category বদলানো হয়েছে।');
    }

    public function deleteCategory(Request $request, FinanceCategory $category): RedirectResponse
    {
        abort_unless($category->business_id === $this->biz($request), 404);
        if ($category->is_system) {
            return back()->with('error', 'System category মোছা যায় না।');
        }
        if (FinanceTransaction::where('category_id', $category->id)->exists()) {
            return back()->with('error', 'এই category-তে entry আছে — মোছা যাবে না।');
        }
        $category->delete();
        return back()->with('success', 'Category মোছা হয়েছে।');
    }

    public function cashflow(Request $request): InertiaResponse
    {
        $biz = $this->biz($request);
        $this->fin->ensureCategories($biz);
        $tab = $request->query('tab', 'ledger');
        $accountId = $request->query('account') ? (int) $request->query('account') : null;
        $categoryId = $request->query('category') ? (int) $request->query('category') : null;
        $direction = $request->query('direction');
        $source = $request->query('source');

        $today = now('Asia/Dhaka');
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $today->copy()->subDays($i);
            $q = FinanceTransaction::where('business_id', $biz)->where('txn_date', $d->toDateString())
                ->whereNull('voided_at')->whereNull('reversal_of');
            $in = round((float) (clone $q)->where('direction', 'in')->sum('amount'), 2);
            $out = round((float) (clone $q)->where('direction', 'out')->sum('amount'), 2);
            $days[] = ['label' => $i === 0 ? 'আজ' : $d->format('d M'), 'in' => $in, 'out' => $out, 'net' => round($in - $out, 2)];
        }

        $rows = FinanceTransaction::where('business_id', $biz)->with(['account', 'category', 'creator'])
            ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($direction, fn ($q) => $q->where('direction', $direction))
            ->when($source, fn ($q) => $q->where('source', $source))
            ->orderByDesc('txn_date')->orderByDesc('id')->limit(300)->get();

        $running = null;
        if ($accountId) {
            $account = FinanceAccount::where('business_id', $biz)->find($accountId);
            $running = $account ? $this->fin->accountBalance($account) : 0.0;
        }
        $list = [];
        foreach ($rows as $t) {
            $row = $this->txnRow($t);
            if ($running !== null) {
                $row['running'] = $running;
                if (! $t->voided_at) {
                    $running = round($running + ($t->direction === 'in' ? -(float) $t->amount : (float) $t->amount), 2);
                }
            }
            $list[] = $row;
        }

        return Inertia::render('Finance/CashFlow', [
            'tab' => $tab,
            'days' => $days,
            'list' => $list,
            'filters' => ['account' => $accountId, 'category' => $categoryId, 'direction' => $direction, 'source' => $source],
            'accounts' => $this->accountsPayload($biz),
            'categories' => $this->categoriesPayload($biz),
            'closedThrough' => $this->fin->closedThrough($biz),
            'canSee' => $this->fin->canSeeBalances($request->user()),
        ]);
    }

    public function closeMonth(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $month = now('Asia/Dhaka')->subMonthNoOverflow()->format('Y-m');
        app(\App\Services\SettingsService::class)->set('finance.' . $biz . '.closed_through', $month, $request->user()->id);
        return back()->with('success', 'আগের মাস বন্ধ করা হয়েছে — ওই মাসের entry আর বদলানো/বাতিল হবে না।');
    }

    public function dailyClose(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $data = $request->validate(['account_id' => 'required|integer', 'counted' => 'required|numeric|min:0']);
        $account = FinanceAccount::where('business_id', $biz)->findOrFail($data['account_id']);
        $expected = $this->fin->accountBalance($account);
        $diff = round((float) $data['counted'] - $expected, 2);
        DailyClosing::updateOrCreate(
            ['business_id' => $biz, 'account_id' => $account->id, 'date' => now('Asia/Dhaka')->toDateString()],
            ['expected' => $expected, 'counted' => $data['counted'], 'difference' => $diff, 'closed_by' => $request->user()->id]
        );
        if (abs($diff) > 0.01) {
            $this->fin->raiseMismatchEvent($biz, $account->name . ' — আশা ছিল ৳' . number_format($expected) . ', হাতে পাওয়া ৳' . number_format((float) $data['counted']) . ' (তফাত ৳' . number_format($diff) . ')।');
            return back()->with('error', 'হিসাব মেলেনি — তফাত ৳' . number_format($diff) . '। Work Inbox-এ task তোলা হয়েছে।');
        }
        return back()->with('success', 'আজকের হিসাব মিলেছে।');
    }

    public function raisePayoutTask(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $this->fin->raiseMismatchEvent($biz, '৭ দিনের বেশি পুরনো Delivered parcel-এর courier payout এখনো আসেনি — Courier Ledger-এ মিলিয়ে দেখুন।');
        return back()->with('success', 'Work Inbox-এ task তোলা হয়েছে।');
    }

    public function exportCsv(Request $request)
    {
        $biz = $this->biz($request);
        $rows = FinanceTransaction::where('business_id', $biz)->whereIn('type', ['income', 'expense'])
            ->with(['account', 'category'])->orderByDesc('txn_date')->orderByDesc('id')->limit(1000)->get();
        $csv = "Date,Description,Category,Account,Type,Amount\n";
        foreach ($rows as $t) {
            $csv .= implode(',', [
                $t->txn_date->toDateString(),
                '"' . str_replace('"', '""', $t->note ?: $this->typeLabel($t)) . '"',
                '"' . ($t->category?->name ?? '') . '"',
                '"' . ($t->account?->name ?? '') . '"',
                $t->type, ($t->direction === 'in' ? '' : '-') . $t->amount,
            ]) . "\n";
        }
        return Response::make($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="income-expense.csv"',
        ]);
    }
}
