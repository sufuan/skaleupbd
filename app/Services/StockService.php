<?php

namespace App\Services;

use App\Models\BranchStock;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every stock change that does NOT come from an order goes through here, so
 * the stock ledger stays the single source of truth: each movement writes a
 * ledger row with the balance before and after.
 */
class StockService
{
    public function adjust(Product $product, int $delta, string $reason, ?string $note, ?User $actor, string $reference = 'ADJUST', ?float $unitCost = null): StockLedger
    {
        return DB::transaction(function () use ($product, $delta, $reason, $note, $actor, $reference, $unitCost) {
            $prev = $product->stock_qty;
            $product->increment('stock_qty', $delta);
            $cost = $unitCost ?? (float) $product->cost;

            $ledger = StockLedger::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'type' => $delta >= 0 ? 'adjust_in' : 'adjust_out',
                'qty' => abs($delta),
                'prev_stock' => $prev,
                'new_stock' => $prev + $delta,
                'unit_cost' => $cost,
                'value_impact' => $delta * $cost,
                'reference' => $reference,
                'user_id' => $actor?->id,
                'reason' => $reason,
                'note' => $note,
            ]);

            // Website integration: this is the single funnel every stock
            // change flows through (manual adjust, opening stock, CSV
            // import, damage/reconcile/transfer, purchase receives), so
            // the WooCommerce stock push is queued from here. The job
            // itself no-ops for shops without a Woo connection and skips
            // unchanged quantities.
            try {
                \App\Jobs\Integrations\PushWooStockJob::dispatchDebounced((int) $product->business_id, (int) $product->id);
            } catch (\Throwable) {
                // Stock movement must never break on the integration layer.
            }

            return $ledger;
        });
    }

    /** Stock Reconciliation: staff counted a physical qty; the difference is booked as an adjustment. */
    public function reconcile(Product $product, int $counted, ?User $actor): ?StockLedger
    {
        $delta = $counted - $product->stock_qty;
        if ($delta === 0) {
            return null;
        }

        return $this->adjust($product, $delta, 'Stock Reconciliation', "Counted {$counted}, system had {$product->stock_qty}", $actor);
    }

    public function damage(Product $product, int $qty, ?string $note, ?User $actor): StockLedger
    {
        return DB::transaction(function () use ($product, $qty, $note, $actor) {
            $prev = $product->stock_qty;
            $product->decrement('stock_qty', $qty);

            return StockLedger::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'type' => 'damage',
                'qty' => $qty,
                'prev_stock' => $prev,
                'new_stock' => $prev - $qty,
                'unit_cost' => $product->cost,
                'value_impact' => -($qty * (float) $product->cost),
                'reference' => 'DAMAGE',
                'user_id' => $actor?->id,
                'reason' => 'Damage / Lost',
                'note' => $note,
            ]);
        });
    }

    /**
     * A transfer moves qty between two branches' branch_stock rows. The
     * business total (products.stock_qty) does not change; both movements
     * are written to the ledger with the branch recorded.
     */
    public function applyTransfer(StockTransfer $transfer, ?User $actor): void
    {
        DB::transaction(function () use ($transfer, $actor) {
            $product = Product::findOrFail($transfer->product_id);
            foreach ([[$transfer->from_branch_id, -$transfer->qty, 'transfer_out'], [$transfer->to_branch_id, $transfer->qty, 'transfer_in']] as [$branchId, $delta, $type]) {
                $row = BranchStock::firstOrCreate(
                    ['branch_id' => $branchId, 'product_id' => $product->id],
                    ['business_id' => $transfer->business_id, 'qty' => 0]
                );
                $row->increment('qty', $delta);
                StockLedger::create([
                    'business_id' => $transfer->business_id,
                    'product_id' => $product->id,
                    'type' => $type,
                    'qty' => $transfer->qty,
                    'prev_stock' => $product->stock_qty,
                    'new_stock' => $product->stock_qty,
                    'unit_cost' => $product->cost,
                    'value_impact' => 0,
                    'reference' => 'TRF-' . $transfer->id,
                    'branch_id' => $branchId,
                    'user_id' => $actor?->id,
                    'note' => $transfer->note,
                ]);
            }
        });
    }
}
