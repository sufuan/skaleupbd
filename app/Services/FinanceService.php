<?php

namespace App\Services;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Order;
use App\Models\User;
use App\Models\WorkEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * M14 Finance — one ledger, every figure from a single source.
 * Balances are arithmetic over ALL transaction rows: a void never deletes,
 * it posts an opposite reversal row, so the sums always reconcile.
 * Report totals (income/expense) count only live rows (not voided, not reversals).
 */
class FinanceService
{
    public const DEFAULT_CATEGORIES = [
        'income' => ['বিক্রি', 'অন্য আয়'],
        'expense' => ['Ad', 'Packaging', 'Courier', 'ভাড়া', 'Internet', 'বেতন', 'Return ক্ষতি', 'অন্যান্য'],
    ];

    public function ensureCategories(int $businessId): void
    {
        if (FinanceCategory::where('business_id', $businessId)->exists()) {
            return;
        }
        foreach (self::DEFAULT_CATEGORIES as $kind => $names) {
            foreach ($names as $i => $name) {
                FinanceCategory::create([
                    'business_id' => $businessId, 'name' => $name, 'kind' => $kind,
                    'is_system' => $name === 'বিক্রি', 'sort' => $i,
                ]);
            }
        }
    }

    public function canSeeBalances(User $user): bool
    {
        return ($user->finance_access ?? 'full') === 'full';
    }

    /** Settings are global key-value; finance prefs are namespaced per business. */
    private function skey(int $businessId, string $name): string
    {
        return 'finance.' . $businessId . '.' . $name;
    }

    public function setting(int $businessId, string $name, mixed $default = null): mixed
    {
        return app(SettingsService::class)->get($this->skey($businessId, $name), $default);
    }

    public function closedThrough(int $businessId): ?string
    {
        $v = $this->setting($businessId, 'closed_through');
        return $v ? (string) $v : null;
    }

    public function monthLocked(int $businessId, string $date): bool
    {
        $closed = $this->closedThrough($businessId);
        return $closed !== null && substr($date, 0, 7) <= $closed;
    }

    /** Post a transaction. Throws on a locked month. */
    public function post(int $businessId, array $data, ?User $actor = null): FinanceTransaction
    {
        $date = $data['txn_date'] ?? now('Asia/Dhaka')->toDateString();
        if ($this->monthLocked($businessId, $date)) {
            throw new \RuntimeException('এই মাস বন্ধ হয়ে গেছে - এই তারিখে entry দেওয়া যাবে না।');
        }
        return FinanceTransaction::create([
            'business_id' => $businessId,
            'account_id' => $data['account_id'],
            'direction' => $data['direction'],
            'type' => $data['type'],
            'amount' => round((float) $data['amount'], 2),
            'category_id' => $data['category_id'] ?? null,
            'note' => $data['note'] ?? null,
            'source' => $data['source'] ?? 'manual',
            'ref_type' => $data['ref_type'] ?? null,
            'ref_id' => $data['ref_id'] ?? null,
            'transfer_pair_id' => $data['transfer_pair_id'] ?? null,
            'reversal_of' => $data['reversal_of'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'txn_date' => $date,
            'created_by' => $actor?->id,
        ]);
    }

    /** Transfer between two accounts: two linked rows, never income/expense. */
    public function transfer(int $businessId, int $fromId, int $toId, float $amount, ?string $note, ?User $actor = null, ?string $date = null): void
    {
        DB::transaction(function () use ($businessId, $fromId, $toId, $amount, $note, $actor, $date) {
            $out = $this->post($businessId, ['account_id' => $fromId, 'direction' => 'out', 'type' => 'transfer', 'amount' => $amount, 'note' => $note, 'txn_date' => $date], $actor);
            $in = $this->post($businessId, ['account_id' => $toId, 'direction' => 'in', 'type' => 'transfer', 'amount' => $amount, 'note' => $note, 'txn_date' => $date], $actor);
            $out->update(['transfer_pair_id' => $in->id]);
            $in->update(['transfer_pair_id' => $out->id]);
        });
    }

    /** Void a transaction (and its transfer pair) by posting reversal rows dated today. */
    public function void(FinanceTransaction $txn, ?User $actor = null): void
    {
        if ($txn->voided_at || $txn->reversal_of) {
            return;
        }
        DB::transaction(function () use ($txn, $actor) {
            $targets = [$txn];
            if ($txn->transfer_pair_id) {
                $pair = FinanceTransaction::find($txn->transfer_pair_id);
                if ($pair && ! $pair->voided_at) {
                    $targets[] = $pair;
                }
            }
            foreach ($targets as $t) {
                $t->update(['voided_at' => now()]);
                $this->post($t->business_id, [
                    'account_id' => $t->account_id,
                    'direction' => $t->direction === 'in' ? 'out' : 'in',
                    'type' => $t->type,
                    'amount' => $t->amount,
                    'category_id' => $t->category_id,
                    'note' => 'বাতিলের উল্টো entry (মূল entry #' . $t->id . ')',
                    'source' => 'system',
                    'reversal_of' => $t->id,
                    'txn_date' => now('Asia/Dhaka')->toDateString(),
                ], $actor);
            }
        });
    }

    public function accountBalance(FinanceAccount $account): float
    {
        $in = (float) FinanceTransaction::where('account_id', $account->id)->where('direction', 'in')->sum('amount');
        $out = (float) FinanceTransaction::where('account_id', $account->id)->where('direction', 'out')->sum('amount');
        return round((float) $account->opening_balance + $in - $out, 2);
    }

    public function totalCash(int $businessId): float
    {
        return round(FinanceAccount::where('business_id', $businessId)->where('active', true)->get()
            ->sum(fn (FinanceAccount $a) => $this->accountBalance($a)), 2);
    }

    /** Live (report) rows: not voided, not reversals. */
    private function liveTxns(int $businessId)
    {
        return FinanceTransaction::where('business_id', $businessId)->whereNull('voided_at')->whereNull('reversal_of');
    }

    /** Period money figures. Dates are Asia/Dhaka calendar days. */
    public function figures(int $businessId, Carbon $from, Carbon $to): array
    {
        $settlements = app(SettlementService::class);
        $delivered = Order::where('business_id', $businessId)->where('status', 'Delivered')
            ->whereBetween('updated_at', [$from, $to])->with('items')->get();
        $revenue = round($delivered->sum(fn (Order $o) => (float) $o->grand_total), 2);
        $productCost = round($delivered->sum(fn (Order $o) => $o->items->sum(fn ($i) => (float) $i->cost * (int) $i->qty)), 2);
        $courierCharges = round($delivered->sum(fn (Order $o) => $settlements->committedCharge($o)), 2);

        $returned = Order::where('business_id', $businessId)->where('status', 'Returned')
            ->whereBetween('updated_at', [$from, $to])->get();
        $rtoLoss = round($returned->sum(fn (Order $o) => $settlements->committedCharge($o)), 2);

        $expense = round((float) $this->liveTxns($businessId)->where('type', 'expense')
            ->whereDate('txn_date', '>=', $from->toDateString())->whereDate('txn_date', '<=', $to->toDateString())->sum('amount'), 2);
        $otherIncome = round((float) $this->liveTxns($businessId)->where('type', 'income')
            ->whereDate('txn_date', '>=', $from->toDateString())->whereDate('txn_date', '<=', $to->toDateString())->sum('amount'), 2);

        $profit = round($revenue + $otherIncome - $productCost - $courierCharges - $rtoLoss - $expense, 2);

        return compact('revenue', 'productCost', 'courierCharges', 'rtoLoss', 'expense', 'otherIncome', 'profit');
    }

    /** COD money bridge: where the delivered money is stuck right now (all-time). */
    public function bridge(int $businessId): array
    {
        $settlements = app(SettlementService::class);
        $delivered = Order::where('business_id', $businessId)->where('status', 'Delivered')->get();
        $sales = round($delivered->sum(fn (Order $o) => (float) $o->grand_total), 2);
        $charges = round($delivered->sum(fn (Order $o) => $settlements->committedCharge($o)), 2);
        $received = round((float) \App\Models\CourierSettlementAllocation::whereHas('order', fn ($q) => $q->where('business_id', $businessId))->sum('amount'), 2);
        $expectedNet = round($delivered->sum(fn (Order $o) => $settlements->expectedNet($o)), 2);
        $still = round($expectedNet - $received, 2);

        // Pathao has no payment API: if any still-owed parcel rides Pathao, the figure is an estimate.
        $estimate = $delivered->contains(function (Order $o) use ($settlements) {
            $driver = $o->courierMethod?->driver;
            return $driver === 'pathao' && $settlements->settledNet($o) < $settlements->expectedNet($o) - 0.01;
        });

        return compact('sales', 'charges', 'received', 'still', 'estimate');
    }

    /** Customer dues: money customers still owe on live (not delivered/cancelled) orders. */
    public function dues(int $businessId): array
    {
        $orders = Order::where('business_id', $businessId)
            ->whereIn('status', ['Pending', 'Processing', 'Shipped'])->get()
            ->filter(fn (Order $o) => $o->due > 0.01);
        return ['total' => round($orders->sum(fn (Order $o) => $o->due), 2), 'count' => $orders->count()];
    }

    /** Order advance → money into the chosen finance account (idempotent per order). */
    public function syncOrderAdvance(Order $order, ?User $actor = null): void
    {
        try {
            $businessId = $order->business_id;
            $key = 'advance-order-' . $order->id;
            // The key family: the base key plus -v{n} reposts (the base key is unique per business,
            // so a repost after a void needs a fresh key while staying in the same family).
            $family = FinanceTransaction::where('business_id', $businessId)
                ->where(fn ($q) => $q->where('idempotency_key', $key)->orWhere('idempotency_key', 'like', $key . '-v%'))
                ->get();
            $existing = $family->whereNull('voided_at')->whereNull('reversal_of')->first();
            $amount = round((float) $order->advance, 2);

            if ($amount <= 0) {
                if ($existing) {
                    $this->void($existing, $actor);
                }
                return;
            }
            if ($existing && abs((float) $existing->amount - $amount) < 0.01) {
                return; // unchanged
            }
            if ($existing) {
                $this->void($existing, $actor); // amount changed: reverse, then repost below
            }
            $postKey = $family->isEmpty() ? $key : $key . '-v' . ($family->count() + 1);
            $accountId = (int) ($this->setting($businessId, 'advance_account_id', 0) ?: 0);
            $account = $accountId
                ? FinanceAccount::where('business_id', $businessId)->where('id', $accountId)->where('active', true)->first()
                : null;
            $account ??= FinanceAccount::where('business_id', $businessId)->where('active', true)
                ->orderByRaw("CASE type WHEN 'mobile' THEN 0 WHEN 'cash' THEN 1 ELSE 2 END")->first();
            if (! $account) {
                return; // finance not set up yet — nothing to post into
            }
            $this->post($businessId, [
                'account_id' => $account->id, 'direction' => 'in', 'type' => 'advance',
                'amount' => $amount, 'source' => 'order', 'ref_type' => 'order', 'ref_id' => $order->id,
                'note' => 'Order ' . $order->invoice_no . ' - advance',
                'idempotency_key' => $postKey, 'txn_date' => now('Asia/Dhaka')->toDateString(),
            ], $actor);
        } catch (\Throwable $e) {
            report($e); // finance must never break an order
        }
    }

    /** Courier payout recorded in Delivery → mirror it into the named finance account (idempotent). */
    public function syncCourierPayment(\App\Models\CourierPayment $payment): void
    {
        try {
            $businessId = $payment->method?->business_id;
            if (! $businessId || ! $payment->deposit_account) {
                return;
            }
            $account = FinanceAccount::where('business_id', $businessId)->where('active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($payment->deposit_account))])->first();
            if (! $account) {
                return;
            }
            $key = 'courier-payment-' . $payment->id;
            if (FinanceTransaction::where('business_id', $businessId)->where('idempotency_key', $key)->exists()) {
                return;
            }
            $this->post($businessId, [
                'account_id' => $account->id, 'direction' => 'in', 'type' => 'courier_payout',
                'amount' => (float) $payment->amount, 'source' => 'courier',
                'ref_type' => 'courier_payment', 'ref_id' => $payment->id,
                'note' => 'Courier payout - ' . ($payment->method?->name ?? ''),
                'idempotency_key' => $key,
                'txn_date' => $payment->date?->toDateString() ?? now('Asia/Dhaka')->toDateString(),
            ]);
        } catch (\Throwable $e) {
            report($e); // finance must never break delivery
        }
    }

    public function raiseMismatchEvent(int $businessId, string $body): void
    {
        WorkEvent::create([
            'business_id' => $businessId, 'kind' => 'finance_mismatch',
            'title' => 'Finance হিসাব মেলেনি', 'body' => $body, 'link' => '/finance',
        ]);
    }
}
