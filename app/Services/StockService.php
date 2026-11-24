<?php

namespace App\Services;

use App\Models\Branch;
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
    public function __construct(private readonly SettingsService $settings) {}

    /** Is per-branch stock counting turned on for this business? */
    public function branchStockEnabled(int $businessId): bool
    {
        return (bool) $this->settings->bizGet($businessId, "inventory.{$businessId}.branch_stock_enabled", 'inventory.branch_stock_enabled', false);
    }

    /**
     * The branch that holds the shop's whole stock pool: the first active
     * branch (the same convention the Branches page derivation and the
     * product-create opening stock use), falling back to the first branch.
     */
    public function defaultBranchId(int $businessId): ?int
    {
        return Branch::where('business_id', $businessId)->where('is_active', true)->orderBy('id')->value('id')
            ?? Branch::where('business_id', $businessId)->orderBy('id')->value('id');
    }

    /**
     * Rebuild branch_stock from the products table: the whole real pool
     * sits on the default branch, every other branch reads zero. Called
     * the moment branch stock is switched ON, so the figures the seller
     * sees from that moment are true (the seeded/legacy rows disagreed
     * with products and nothing ever reconciled them - Hunt K-H4).
     */
    public function rederiveBranchStock(int $businessId): void
    {
        DB::transaction(function () use ($businessId) {
            BranchStock::where('business_id', $businessId)->delete();
            $defaultBranchId = $this->defaultBranchId($businessId);
            if ($defaultBranchId === null) {
                return;
            }
            foreach (Product::where('business_id', $businessId)->get(['id', 'stock_qty']) as $p) {
                BranchStock::create([
                    'business_id' => $businessId,
                    'branch_id' => $defaultBranchId,
                    'product_id' => $p->id,
                    'qty' => (int) $p->stock_qty,
                ]);
            }
        });
    }

    /**
     * Keep branch_stock in step with a products.stock_qty movement: while
     * branch stock is ON, every change to the pool total lands on the
     * default branch by the same amount, so SUM(branch_stock) per product
     * always equals products.stock_qty. Every stock-writing path (this
     * service's adjust/damage and OrderService's ship/return moves) calls
     * this one method - the sync logic lives here, never scattered.
     */
    public function syncDefaultBranchStock(Product $product, int $delta): void
    {
        if ($delta === 0 || ! $this->branchStockEnabled((int) $product->business_id)) {
            return;
        }
        $defaultBranchId = $this->defaultBranchId((int) $product->business_id);
        if ($defaultBranchId === null) {
            return;
        }
        $row = BranchStock::firstOrCreate(
            ['branch_id' => $defaultBranchId, 'product_id' => $product->id],
            ['business_id' => $product->business_id, 'qty' => 0]
        );
        $row->increment('qty', $delta);
    }

    public function adjust(Product $product, int $delta, string $reason, ?string $note, ?User $actor, string $reference = 'ADJUST', ?float $unitCost = null): StockLedger
    {
        return DB::transaction(function () use ($product, $delta, $reason, $note, $actor, $reference, $unitCost) {
            // Floor (Hunt W W7): a manual movement may never take the
            // stock below zero, nor eat into units already reserved for
            // live orders - the order paths refuse overselling, and the
            // seller-facing Adjust/opening-stock path must refuse too.
            // Throws before anything is written, so no ledger row and
            // no branch sync happen for a refused adjustment.
            if ($delta < 0) {
                $newQty = (int) $product->stock_qty + $delta;
                if ($newQty < 0) {
                    throw new \RuntimeException(__('This adjustment would take the stock below zero.'));
                }
                if ($newQty < (int) $product->reserved_qty) {
                    throw new \RuntimeException(__('This adjustment would use stock that is reserved for orders.'));
                }
            }
            $prev = $product->stock_qty;
            $product->increment('stock_qty', $delta);
            $this->syncDefaultBranchStock($product, $delta);
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
            $this->syncDefaultBranchStock($product, -$qty);

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
