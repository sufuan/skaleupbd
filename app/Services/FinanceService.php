<?php

namespace App\Services;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WarrantyClaim;
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
            throw new \RuntimeException(__('This month is already closed - no entry can be added on this date.'));
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
                    'note' => __('Reversal of voided entry (original entry #:id)', ['id' => $t->id]),
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

    /**
     * COD money bridge: where the courier-held money is stuck right now
     * (all-time). Reads SettlementService::courierTotals — the same numbers
     * as the Courier Ledger and receivables. Only parcels actually booked
     * with a courier count: a manual delivery is cash in hand, never
     * courier-held, and its delivery charge is not a courier charge.
     * ('sales' is the COD those booked parcels collected.)
     */
    public function bridge(int $businessId): array
    {
        $settlements = app(SettlementService::class);
        $totals = $settlements->courierTotals($businessId);
        $sales = $totals['collected'];
        $charges = $totals['charges'];
        $received = $totals['settled'];
        $still = $totals['still'];

        // Pathao has no payment API: if any still-owed parcel rides Pathao, the figure is an estimate.
        $estimate = $settlements->courierDelivered($businessId)->contains(function (Order $o) use ($settlements) {
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
                'note' => __('Order :invoice - advance', ['invoice' => $order->invoice_no]),
                'idempotency_key' => $postKey, 'txn_date' => now('Asia/Dhaka')->toDateString(),
            ], $actor);
        } catch (\Throwable $e) {
            report($e); // finance must never break an order
        }
    }

    /** The live advance row collected for an order (not voided, not a reversal), if any. */
    public function orderAdvanceTxn(Order $order): ?FinanceTransaction
    {
        $key = 'advance-order-' . $order->id;

        return FinanceTransaction::where('business_id', $order->business_id)
            ->where(fn ($q) => $q->where('idempotency_key', $key)->orWhere('idempotency_key', 'like', $key . '-v%'))
            ->whereNull('voided_at')->whereNull('reversal_of')
            ->latest('id')->first();
    }

    /** Whether an order's collected advance has already been refunded. */
    public function advanceRefunded(Order $order): bool
    {
        return FinanceTransaction::where('business_id', $order->business_id)
            ->where('idempotency_key', 'advance-refund:order:' . $order->id)->exists();
    }

    /**
     * Refund a cancelled order's collected advance: exactly one entry out
     * of the account that holds the advance, keyed
     * advance-refund:order:{id} so it can never post twice (a repeat call
     * returns the existing row). The money stays untouched until the seller
     * runs this; the order's points stay deducted (owner rule).
     *
     * The entry posts as type 'advance_refund', NOT 'expense': the advance
     * itself posts as type 'advance' and is never counted as income, so
     * handing it back settles a liability and must not reduce profit
     * either. Cash balances are unaffected by the label — balances sum
     * every row; only the P&L classification changes.
     */
    public function refundOrderAdvance(Order $order, ?User $actor = null): FinanceTransaction
    {
        $key = 'advance-refund:order:' . $order->id;
        $existing = FinanceTransaction::where('business_id', $order->business_id)->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing;
        }
        $advanceTxn = $this->orderAdvanceTxn($order);
        $amount = round((float) ($advanceTxn?->amount ?? $order->advance), 2);
        if ($amount <= 0) {
            throw new \DomainException(__('This order has no collected advance to refund.'));
        }
        $account = $advanceTxn?->account;
        if (! $account) {
            $accountId = (int) ($this->setting($order->business_id, 'advance_account_id', 0) ?: 0);
            $account = $accountId
                ? FinanceAccount::where('business_id', $order->business_id)->where('id', $accountId)->where('active', true)->first()
                : null;
            $account ??= FinanceAccount::where('business_id', $order->business_id)->where('active', true)
                ->orderByRaw("CASE type WHEN 'mobile' THEN 0 WHEN 'cash' THEN 1 ELSE 2 END")->first();
        }
        if (! $account) {
            throw new \DomainException(__('No finance account is set up to refund from.'));
        }

        return $this->post($order->business_id, [
            'account_id' => $account->id, 'direction' => 'out', 'type' => 'advance_refund',
            'amount' => $amount, 'source' => 'order', 'ref_type' => 'order', 'ref_id' => $order->id,
            'note' => __('Advance refund - order :invoice', ['invoice' => $order->invoice_no]),
            'idempotency_key' => $key, 'txn_date' => now('Asia/Dhaka')->toDateString(),
        ], $actor);
    }

    /**
     * Y3-F8: closing a warranty claim as "Refunded" posts the money
     * back out - until now the refund existed only as a word on the
     * claim and neither P&L nor cash ever saw it. The claim row holds
     * NO amount of its own, so the amount is the price the customer
     * actually paid for the claimed item: the linked order line's
     * snapshot price (via the claim's order_id, or its invoice_no),
     * falling back to the claimed product's current price when the
     * claim is not tied to an order line. The entry posts as a plain
     * 'expense' (the type P&L and Cash Flow sum) with the claim as its
     * reference, and is idempotent per claim.
     */
    public function refundWarrantyClaim(WarrantyClaim $claim, ?User $actor = null): FinanceTransaction
    {
        $key = 'warranty-refund:claim:' . $claim->id;
        $existing = FinanceTransaction::where('business_id', $claim->business_id)->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing;
        }
        $amount = 0.0;
        $order = null;
        if ($claim->order_id) {
            $order = Order::where('business_id', $claim->business_id)->find($claim->order_id);
        }
        if (! $order && $claim->invoice_no) {
            $order = Order::where('business_id', $claim->business_id)->where('invoice_no', $claim->invoice_no)->first();
        }
        if ($order && $claim->product_id) {
            $item = $order->items()->where('product_id', $claim->product_id)->first();
            if ($item) {
                $amount = (float) $item->price;
            }
        }
        if ($amount <= 0 && $claim->product_id) {
            $product = Product::where('business_id', $claim->business_id)->find($claim->product_id);
            if ($product) {
                $amount = (float) $product->price;
            }
        }
        if ($amount <= 0) {
            throw new \DomainException(__('No refundable amount could be worked out for this claim.'));
        }
        $account = FinanceAccount::where('business_id', $claim->business_id)->where('active', true)
            ->orderByRaw("CASE type WHEN 'mobile' THEN 0 WHEN 'cash' THEN 1 ELSE 2 END")->first();
        if (! $account) {
            throw new \DomainException(__('No finance account is set up to refund from.'));
        }

        return $this->post($claim->business_id, [
            'account_id' => $account->id, 'direction' => 'out', 'type' => 'expense',
            'amount' => $amount, 'source' => 'warranty', 'ref_type' => 'warranty_claim', 'ref_id' => $claim->id,
            'note' => __('Warranty refund - claim :claim', ['claim' => $claim->claim_no]),
            'idempotency_key' => $key, 'txn_date' => now('Asia/Dhaka')->toDateString(),
        ], $actor);
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
                'note' => __('Courier payout - :name', ['name' => $payment->method?->name ?? '']),
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
            'title' => __('Finance accounts did not match'), 'body' => $body, 'link' => '/finance',
        ]);
    }
}
