<?php

namespace App\Services;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchasePayment;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * M5 Purchase — the money + stock rules live here so the controller stays
 * thin and the engine checks can prove every figure:
 *
 *  - A credit purchase moves NO cash: creating one posts nothing to
 *    Finance. Its due is derived (total − allocated payments).
 *  - A payment posts exactly ONE Finance entry (direction out,
 *    ref_type purchase_payment) and allocates FIFO across the supplier's
 *    unpaid bills (or directly to one purchase).
 *  - Receiving posts stock ONLY through StockService::adjust() into
 *    stock_ledger; damaged/missing qty never enters stock.
 */
class PurchaseService
{
    public function __construct(
        private StockService $stock,
        private FinanceService $finance,
    ) {}

    // ---------- creation ----------

    /**
     * @param  array<int, array{product_id:int, qty:int, unit_cost:float}>  $lines
     */
    public function createBill(int $biz, array $data, array $lines, ?User $actor): Purchase
    {
        return DB::transaction(function () use ($biz, $data, $lines) {
            $total = 0.0;
            foreach ($lines as $l) {
                $total += (int) $l['qty'] * (float) $l['unit_cost'];
            }
            $purchase = Purchase::create([
                'business_id' => $biz,
                'supplier_id' => $data['supplier_id'],
                'date' => $data['date'],
                'note' => $data['note'] ?? null,
                'tags' => $data['tags'] ?? null,
                'total' => round($total, 2),
                'kind' => 'bill',
            ]);
            foreach ($lines as $l) {
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'business_id' => $biz,
                    'product_id' => (int) $l['product_id'],
                    'qty_ordered' => (int) $l['qty'],
                    'qty_received' => 0,
                    'qty_damaged' => 0,
                    'unit_cost' => round((float) $l['unit_cost'], 2),
                ]);
            }

            return $purchase;
        });
    }

    /**
     * Quick Stock: no supplier, no bill — instantly fully received, no due.
     *
     * @param  array<int, array{product_id:int, qty:int, unit_cost:float}>  $lines
     */
    public function createQuickStock(int $biz, array $data, array $lines, ?User $actor): Purchase
    {
        return DB::transaction(function () use ($biz, $data, $lines, $actor) {
            $total = 0.0;
            foreach ($lines as $l) {
                $total += (int) $l['qty'] * (float) $l['unit_cost'];
            }
            $purchase = Purchase::create([
                'business_id' => $biz,
                'supplier_id' => null,
                'date' => $data['date'],
                'note' => $data['note'] ?? null,
                'tags' => null,
                'total' => round($total, 2),
                'kind' => 'quick',
            ]);
            foreach ($lines as $l) {
                $item = PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'business_id' => $biz,
                    'product_id' => (int) $l['product_id'],
                    'qty_ordered' => (int) $l['qty'],
                    'qty_received' => (int) $l['qty'],
                    'qty_damaged' => 0,
                    'unit_cost' => round((float) $l['unit_cost'], 2),
                ]);
                $product = Product::where('business_id', $biz)->findOrFail($item->product_id);
                $this->stock->adjust($product, (int) $l['qty'], 'Quick Stock', 'Quick Stock · Purchase #' . $purchase->id, $actor, 'PUR-' . $purchase->id, (float) $l['unit_cost']);
            }

            return $purchase;
        });
    }

    // ---------- receiving ----------

    /**
     * @param  array<int, array{received:int, damaged:int}>  $lines  keyed by purchase_item id
     */
    public function receive(Purchase $purchase, array $lines, ?User $actor): void
    {
        DB::transaction(function () use ($purchase, $lines, $actor) {
            foreach ($lines as $itemId => $l) {
                $item = PurchaseItem::where('purchase_id', $purchase->id)->find($itemId);
                if (! $item) {
                    continue;
                }
                $received = max(0, (int) ($l['received'] ?? 0));
                $damaged = max(0, (int) ($l['damaged'] ?? 0));
                if ($received + $damaged > $item->left()) {
                    throw new \RuntimeException('Received + damaged cannot be more than what is left.');
                }
                if ($received === 0 && $damaged === 0) {
                    continue;
                }
                $item->qty_received += $received;
                $item->qty_damaged += $damaged;
                $item->save();
                if ($received > 0) {
                    $product = Product::where('business_id', $purchase->business_id)->findOrFail($item->product_id);
                    $this->stock->adjust($product, $received, 'Purchase Receive', 'Purchase #' . $purchase->id . ' received', $actor, 'PUR-' . $purchase->id, (float) $item->unit_cost);
                }
            }
        });
    }

    // ---------- payments ----------

    /** The Finance expense category supplier payments post under (find-or-create). */
    public function paymentCategory(int $biz): FinanceCategory
    {
        $this->finance->ensureCategories($biz);

        return FinanceCategory::firstOrCreate(
            ['business_id' => $biz, 'name' => 'Supplier Payment', 'kind' => 'expense'],
            ['is_system' => false, 'sort' => 90]
        );
    }

    /**
     * Pay a supplier: ONE Finance entry, allocated FIFO (oldest bill
     * first) across that supplier's unpaid purchases.
     *
     * @return PurchasePayment[] the allocation rows created
     */
    public function paySupplier(int $biz, Supplier $supplier, float $amount, int $accountId, string $date, ?string $note, ?User $actor): array
    {
        return DB::transaction(function () use ($biz, $supplier, $amount, $accountId, $date, $note, $actor) {
            $account = FinanceAccount::where('business_id', $biz)->findOrFail($accountId);
            $entry = $this->finance->post($biz, [
                'account_id' => $account->id,
                'direction' => 'out',
                'type' => 'expense',
                'amount' => $amount,
                'category_id' => $this->paymentCategory($biz)->id,
                'note' => $note ?: ('Supplier payment — ' . $supplier->name),
                'source' => 'purchase',
                'ref_type' => 'purchase_payment',
                'ref_id' => $supplier->id,
                'txn_date' => $date,
                'idempotency_key' => 'purpay-' . $biz . '-' . uniqid(),
            ], $actor);

            $rows = [];
            $remaining = round($amount, 2);
            $bills = Purchase::where('business_id', $biz)->where('supplier_id', $supplier->id)
                ->where('kind', 'bill')->orderBy('date')->orderBy('id')->get();
            foreach ($bills as $bill) {
                if ($remaining <= 0) {
                    break;
                }
                $due = $bill->due();
                if ($due <= 0) {
                    continue;
                }
                $alloc = min($due, $remaining);
                $rows[] = PurchasePayment::create([
                    'business_id' => $biz,
                    'supplier_id' => $supplier->id,
                    'purchase_id' => $bill->id,
                    'amount' => $alloc,
                    'date' => $date,
                    'finance_entry_id' => $entry->id,
                ]);
                $remaining = round($remaining - $alloc, 2);
            }
            // Any remainder (overpayment) stays as an unallocated row so
            // the payment total still reconciles with the Finance entry.
            if ($remaining > 0) {
                $rows[] = PurchasePayment::create([
                    'business_id' => $biz,
                    'supplier_id' => $supplier->id,
                    'purchase_id' => null,
                    'amount' => $remaining,
                    'date' => $date,
                    'finance_entry_id' => $entry->id,
                ]);
            }

            return $rows;
        });
    }

    /** Pay one purchase directly: ONE Finance entry + one allocation row. */
    public function payPurchase(int $biz, Purchase $purchase, float $amount, int $accountId, string $date, ?string $note, ?User $actor): PurchasePayment
    {
        return DB::transaction(function () use ($biz, $purchase, $amount, $accountId, $date, $note, $actor) {
            $account = FinanceAccount::where('business_id', $biz)->findOrFail($accountId);
            $entry = $this->finance->post($biz, [
                'account_id' => $account->id,
                'direction' => 'out',
                'type' => 'expense',
                'amount' => $amount,
                'category_id' => $this->paymentCategory($biz)->id,
                'note' => $note ?: ('Supplier payment — ' . ($purchase->supplier?->name ?? '')),
                'source' => 'purchase',
                'ref_type' => 'purchase_payment',
                'ref_id' => $purchase->id,
                'txn_date' => $date,
                'idempotency_key' => 'purpay-' . $biz . '-' . uniqid(),
            ], $actor);

            return PurchasePayment::create([
                'business_id' => $biz,
                'supplier_id' => $purchase->supplier_id,
                'purchase_id' => $purchase->id,
                'amount' => $amount,
                'date' => $date,
                'finance_entry_id' => $entry->id,
            ]);
        });
    }

    // ---------- aggregates (one dataset behind every page) ----------

    public function totalDue(int $biz): float
    {
        $paid = PurchasePayment::where('business_id', $biz)->whereNotNull('purchase_id')->sum('amount');
        $billed = Purchase::where('business_id', $biz)->where('kind', 'bill')->sum('total');

        return round(max(0, (float) $billed - (float) $paid), 2);
    }

    /** Purchases of this business with their derived due attached. */
    public function billsWithDue(int $biz)
    {
        $paidByPurchase = PurchasePayment::where('business_id', $biz)->whereNotNull('purchase_id')
            ->selectRaw('purchase_id, SUM(amount) as paid')->groupBy('purchase_id')
            ->pluck('paid', 'purchase_id');

        return Purchase::where('business_id', $biz)->where('kind', 'bill')
            ->with('supplier')->orderBy('date')->orderBy('id')->get()
            ->map(function (Purchase $p) use ($paidByPurchase) {
                $p->paid_amount = round((float) ($paidByPurchase[$p->id] ?? 0), 2);
                $p->due_amount = round(max(0, (float) $p->total - $p->paid_amount), 2);

                return $p;
            });
    }

    /** Per-supplier money summary: purchases, total purchased, due, overdue, oldest due date, last purchase/activity. */
    public function supplierSummaries(int $biz, ?string $today = null): array
    {
        $today = $today ?? now('Asia/Dhaka')->toDateString();
        $bills = $this->billsWithDue($biz);
        $lastActivity = PurchasePayment::where('business_id', $biz)
            ->selectRaw('supplier_id, MAX(date) as d')->groupBy('supplier_id')->pluck('d', 'supplier_id');

        $out = [];
        foreach (Supplier::where('business_id', $biz)->orderBy('name')->get() as $s) {
            $mine = $bills->where('supplier_id', $s->id);
            $due = round($mine->sum('due_amount'), 2);
            $overdueRows = $mine->filter(fn (Purchase $p) => $p->due_amount > 0
                && $p->date->copy()->addDays((int) $s->terms_days)->toDateString() < $today);
            $oldest = $overdueRows->isEmpty()
                ? ($mine->where('due_amount', '>', 0)->min('date'))
                : $overdueRows->min('date');
            $lastPurchase = $mine->max('date');
            $last = collect([$lastPurchase?->toDateString(), $lastActivity[$s->id] ?? null])->filter()->max();
            $out[] = [
                'supplier' => $s,
                'purchases_count' => $mine->count(),
                'total_purchased' => round((float) $mine->sum('total'), 2),
                'due' => $due,
                'bills_due' => $mine->where('due_amount', '>', 0)->count(),
                'overdue' => round((float) $overdueRows->sum('due_amount'), 2),
                'oldest_due_date' => $oldest ? \Carbon\Carbon::parse($oldest)->toDateString() : null,
                'last_purchase' => $lastPurchase?->toDateString(),
                'last_activity' => $last,
            ];
        }

        return $out;
    }

    public function overdueTotals(int $biz, ?string $today = null): array
    {
        $rows = collect($this->supplierSummaries($biz, $today))->filter(fn ($r) => $r['overdue'] > 0);

        return [
            'amount' => round((float) $rows->sum('overdue'), 2),
            'suppliers' => $rows->count(),
            'rows' => $rows->values()->all(),
        ];
    }

    /** Count of purchase LINES still waiting to be fully received (dashboard + Receive queue). */
    public function toReceiveLineCount(int $biz): int
    {
        return PurchaseItem::where('business_id', $biz)
            ->whereRaw('qty_received + qty_damaged < qty_ordered')->count();
    }

    public function toReceivePurchaseCount(int $biz): int
    {
        return Purchase::where('business_id', $biz)
            ->whereHas('items', fn ($q) => $q->whereRaw('qty_received + qty_damaged < qty_ordered'))
            ->count();
    }

    public function notReceivedValue(int $biz): float
    {
        return round((float) PurchaseItem::where('business_id', $biz)
            ->selectRaw('SUM((qty_ordered - qty_received - qty_damaged) * unit_cost) as v')->value('v'), 2);
    }

    /** Products at/below their reorder point (alert_qty) — one definition everywhere. */
    public function reorderProducts(int $biz)
    {
        return Product::where('business_id', $biz)->where('is_active', true)
            ->whereColumn('stock_qty', '<=', 'alert_qty')
            ->orderBy('stock_qty')->get();
    }
}
