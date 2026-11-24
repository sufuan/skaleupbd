<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Services\StockService;
use App\Support\DhakaTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class InventoryOpsController extends Controller
{
    public function __construct(private StockService $stock, private readonly \App\Services\SettingsService $settings) {}

    private function biz(Request $request): int
    {
        return \App\Support\ShopContext::id($request);
    }

    // ---------------- Stock Ledger + Integrity ----------------

    public function ledger(Request $request)
    {
        $biz = $this->biz($request);
        $q = StockLedger::where('business_id', $biz)->with(['product:id,name,sku,image', 'user:id,name']);
        if ($s = $request->query('search')) {
            $q->whereHas('product', fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"));
        }
        if ($t = $request->query('type')) {
            $q->where('type', $t);
        }
        if ($d = $request->query('direction')) {
            $q->whereIn('type', $d === 'in'
                ? ['return_in', 'adjust_in', 'transfer_in']
                : ['ship_out', 'adjust_out', 'transfer_out', 'damage']);
        }
        if ($from = $request->query('from')) {
            $q->where('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('created_at', '<=', $to . ' 23:59:59');
        }
        $rows = $q->orderByDesc('id')->paginate(25)->withQueryString();

        $all = StockLedger::where('business_id', $biz);
        // Variant movements (variant_id set) belong to the variant's own
        // stock pool, which products.stock_qty never carried - folding
        // them into the product-pool cards/replay would fake a mismatch
        // on every variant sale. They still list as rows below.
        $poolRows = (clone $all)->whereNull('variant_id');
        $inQty = (clone $poolRows)->whereIn('type', ['return_in', 'adjust_in', 'transfer_in'])->sum('qty');
        $outQty = (clone $poolRows)->whereIn('type', ['ship_out', 'adjust_out', 'transfer_out', 'damage'])->sum('qty');
        // The "Stock Out" card means units that left through SALES (ship_out)
        // — adjustments and damage have their own cards, and folding them in
        // here counted a ±20 adjustment as sales out.
        $salesOutQty = (clone $poolRows)->where('type', 'ship_out')->sum('qty');
        $adjNet = (clone $poolRows)->where('type', 'adjust_in')->sum('qty') - (clone $poolRows)->where('type', 'adjust_out')->sum('qty');
        $damageQty = (clone $poolRows)->where('type', 'damage')->sum('qty');
        $closing = (int) Product::where('business_id', $biz)->sum('stock_qty');

        // Integrity: replay each product's ledger (signed) and compare with its stored stock_qty.
        $signed = ['reserve' => 0, 'release_reserve' => 0, 'ship_out' => -1, 'return_in' => 1,
            'adjust_in' => 1, 'adjust_out' => -1, 'transfer_in' => 0, 'transfer_out' => 0, 'damage' => -1];
        $balances = StockLedger::where('business_id', $biz)->whereNull('variant_id')->get(['product_id', 'type', 'qty'])
            ->groupBy('product_id')
            ->map(fn ($rows) => $rows->sum(fn ($r) => ($signed[$r->type] ?? 0) * $r->qty));
        $products = Product::where('business_id', $biz)->get();
        $openings = (array) $this->settings->bizGet($biz, "inventory.{$biz}.opening_balances", 'inventory.opening_balances', []);
        $findings = [];
        $accurate = 0;
        foreach ($products as $p) {
            // Opening balance = stock that existed before the first ledger row; seeded
            // by the inventory seeder, or derived as current minus net movement.
            $net = (int) ($balances[$p->id] ?? 0);
            $opening = array_key_exists($p->id, $openings) ? (int) $openings[$p->id] : ($p->stock_qty - $net);
            $expected = $opening + $net;
            if ($expected === $p->stock_qty) {
                $accurate++;
            } else {
                $findings[] = ['kind' => 'Ledger Mismatch', 'product' => $p->name, 'sku' => $p->sku,
                    'text' => "ledger says {$expected}, stock shows {$p->stock_qty}"];
            }
            if ($p->type === 'combo' && $p->components()->exists()) {
                // A combo with a recipe carries no stock of its own -
                // every movement belongs on its components (K-H1). Any
                // units sitting on the combo's own row are drift: they
                // promise stock no component backs.
                if ((int) $p->stock_qty !== 0 || (int) $p->reserved_qty !== 0) {
                    $findings[] = ['kind' => 'Combo Parent Drift', 'product' => $p->name, 'sku' => $p->sku,
                        'text' => "combo carries its own stock ({$p->stock_qty}) / reservations ({$p->reserved_qty}) - movements belong on its components"];
                }
            }
        }
        $accuracy = $products->count() ? round($accurate / $products->count() * 100, 1) : 100;

        return Inertia::render('Inventory/Ledger', [
            'rows' => $rows->through(fn ($r) => [
                'id' => $r->id,
                'created_at' => DhakaTime::fmt($r->created_at, 'd M Y, h:i A'),
                'product' => $r->product ? ['name' => $r->product->name, 'sku' => $r->product->sku, 'image' => $r->product->image] : null,
                'type' => $r->type,
                // A reserve is a hold, not an in/out — labelling it '—' made
                // reserve rows read as empty. Name what actually happened.
                'direction' => in_array($r->type, ['return_in', 'adjust_in', 'transfer_in'], true) ? 'In' : ($r->type === 'reserve' ? 'Hold' : ($r->type === 'release_reserve' ? 'Released' : 'Out')),
                'qty' => $r->qty,
                'prev_stock' => $r->prev_stock,
                'new_stock' => $r->new_stock,
                'unit_cost' => $r->unit_cost,
                'value_impact' => $r->value_impact,
                'reference' => $r->reference,
                'branch' => $r->branch_id ? Branch::find($r->branch_id)?->name : null,
                'updated_by' => $r->user?->name,
                'reason' => $r->reason,
                'note' => $r->note,
                'status' => $r->status,
            ]),
            'cards' => [
                'opening' => $closing - ((int) $inQty - (int) $outQty),
                'stock_in' => (int) $inQty,
                'stock_out' => (int) $salesOutQty,
                'closing' => $closing,
                'adjustment' => (int) $adjNet,
                'damage' => (int) $damageQty,
            ],
            'rail' => [
                'total_movements' => (clone $all)->count(),
                // Opening stock on product creation / import is written as an
                // adjustment row but is NOT a manual adjustment — exclude it.
                'manual_today' => (clone $all)->whereIn('type', ['adjust_in', 'adjust_out'])
                    ->whereNotIn('reason', ['Initial Stock', 'Import'])
                    ->whereBetween('created_at', DhakaTime::dayBoundsUtc(DhakaTime::today()))->count(),
                'accuracy' => $accuracy,
            ],
            'findings' => $findings,
            'products' => Product::where('business_id', $biz)->orderBy('name')->get(['id', 'name', 'sku', 'stock_qty']),
            'insights' => [
                'low_stock' => Product::where('business_id', $biz)->get()->filter(fn ($p) => ($p->stock_qty - $p->reserved_qty) > 0 && ($p->stock_qty - $p->reserved_qty) <= $p->alert_qty)->count(),
                'stockout_risk' => Product::where('business_id', $biz)->get()->filter(fn ($p) => ($p->stock_qty - $p->reserved_qty) <= 0)->count(),
                'fast_moving' => StockLedger::where('business_id', $biz)->where('type', 'ship_out')->select('product_id', DB::raw('SUM(qty) as total'))->groupBy('product_id')->having('total', '>=', 10)->count(),
                'high_adjustments' => StockLedger::where('business_id', $biz)->whereIn('type', ['adjust_in', 'adjust_out'])->select('product_id', DB::raw('COUNT(*) as c'))->groupBy('product_id')->having('c', '>=', 3)->count(),
            ],
            'filters' => $request->only(['search', 'type', 'direction', 'from', 'to']),
        ]);
    }

    public function reconcile(Request $request)
    {
        $data = $request->validate([
            'counts' => ['required', 'array'],
            'counts.*' => ['integer', 'min:0'],
        ]);
        $biz = $this->biz($request);
        $changed = 0;
        foreach ($data['counts'] as $pid => $counted) {
            $p = Product::where('business_id', $biz)->find($pid);
            if ($p && $this->stock->reconcile($p, (int) $counted, $request->user())) {
                $changed++;
            }
        }

        return back()->with('success', $changed ? "Reconciliation saved - {$changed} product(s) adjusted" : 'Everything already matches');
    }

    // ---------------- Branches + Transfers ----------------

    public function branches(Request $request)
    {
        $biz = $this->biz($request);
        $branchStockEnabled = (bool) $this->settings->bizGet($biz, "inventory.{$biz}.branch_stock_enabled", 'inventory.branch_stock_enabled', false);
        // Hunt G-3: while branch stock is OFF, nothing keeps branch_stock
        // in sync (receives, imports, shipments and deletes never touch
        // it), so the page must not present it as live fact. Derive the
        // figures from the products table instead: the whole real stock
        // pool sits in the default branch (the first active branch, the
        // same convention storeProduct uses for opening stock), and every
        // other branch honestly shows zero.
        $realProducts = $branchStockEnabled ? collect() : Product::where('business_id', $biz)->orderBy('name')->get();
        $defaultBranchId = Branch::where('business_id', $biz)->where('is_active', true)->orderBy('id')->value('id')
            ?? Branch::where('business_id', $biz)->orderBy('id')->value('id');
        $branches = Branch::where('business_id', $biz)->orderBy('id')->get()->map(function ($b) use ($branchStockEnabled, $realProducts, $defaultBranchId) {
            if ($branchStockEnabled) {
                $stock = BranchStock::where('branch_id', $b->id)->get();
                $rows = $stock->map(fn ($s) => ['product_id' => $s->product_id, 'qty' => $s->qty])->values();
                $count = $stock->count();
                $total = (int) $stock->sum('qty');
            } elseif ($b->id === $defaultBranchId) {
                $rows = $realProducts->map(fn ($prod) => ['product_id' => $prod->id, 'qty' => (int) $prod->stock_qty])->values();
                $count = $realProducts->count();
                $total = (int) $realProducts->sum('stock_qty');
            } else {
                $rows = collect();
                $count = 0;
                $total = 0;
            }
            return [
                'id' => $b->id, 'name' => $b->name, 'type' => $b->type, 'address' => $b->address,
                'phone' => $b->phone, 'is_active' => (bool) $b->is_active,
                'products_count' => $count,
                'total_qty' => $total,
                'stock' => $rows,
            ];
        });
        $transfers = StockTransfer::where('business_id', $biz)->orderByDesc('id')->get()->map(fn ($t) => [
            'id' => $t->id, 'from_branch_id' => $t->from_branch_id, 'to_branch_id' => $t->to_branch_id,
            'product_id' => $t->product_id, 'qty' => $t->qty, 'cartons' => $t->cartons, 'status' => $t->status, 'note' => $t->note,
            'created_at' => DhakaTime::fmt($t->created_at, 'd M Y'),
        ]);

        return Inertia::render('Inventory/Branches', [
            'branches' => $branches,
            'stats' => [
                'branches' => $branches->count(),
                'stores' => $branches->where('type', 'store')->count(),
                'warehouses' => $branches->where('type', 'warehouse')->count(),
                'central' => $branches->where('type', 'central')->count(),
            ],
            'branchStockEnabled' => $branchStockEnabled,
            'cartonsEnabled' => (bool) $this->settings->bizGet($biz, "inventory.{$biz}.warehouse_cartons", 'inventory.warehouse_cartons', false),
            'transfers' => $transfers,
            'products' => Product::where('business_id', $biz)->orderBy('name')->get(['id', 'name', 'sku', 'stock_qty']),
        ]);
    }

    public function toggleBranchStock(Request $request)
    {
        $biz = $this->biz($request);
        $enabled = ! (bool) $this->settings->bizGet($biz, "inventory.{$biz}.branch_stock_enabled", 'inventory.branch_stock_enabled', false);
        $this->settings->set("inventory.{$biz}.branch_stock_enabled", $enabled, $request->user()?->id);
        if ($enabled) {
            // Hunt K-H4: turning the feature ON must start from the truth.
            // The stored branch_stock rows were never kept in sync while
            // the feature was off (and the seeded rows never matched), so
            // re-derive them from products before the page believes them.
            $this->stock->rederiveBranchStock($biz);
        }

        return back()->with('success', $enabled ? 'Branch stock turned on' : 'Branch stock turned off');
    }

    public function storeBranch(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:store,warehouse,central'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
        Branch::create(['business_id' => $this->biz($request)] + $data);

        return back()->with('success', 'Branch added');
    }

    public function updateBranch(Request $request, Branch $branch)
    {
        abort_unless($branch->business_id === $this->biz($request), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:store,warehouse,central'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_active' => ['boolean'],
        ]);
        $branch->update($data);

        return back()->with('success', 'Branch updated');
    }

    public function destroyBranch(Request $request, Branch $branch)
    {
        abort_unless($branch->business_id === $this->biz($request), 403);
        BranchStock::where('branch_id', $branch->id)->delete();
        $branch->delete();

        return back()->with('success', 'Branch deleted');
    }

    public function storeTransfer(Request $request)
    {
        $data = $request->validate([
            'from_branch_id' => ['required', 'integer'],
            'to_branch_id' => ['required', 'integer', 'different:from_branch_id'],
            'product_id' => ['required', 'integer'],
            'qty' => ['required', 'integer', 'min:1'],
            'cartons' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $biz = $this->biz($request);
        // Hunt K-H5: a transfer may not exceed what the source branch
        // actually holds for this product - the old check-less path let
        // one typo drive a branch to -997. "Available" mirrors exactly
        // what the Branches page shows: the branch_stock row while the
        // feature is ON; while OFF, the whole pool on the default branch
        // and zero anywhere else (the page's own derivation).
        $product = Product::where('business_id', $biz)->find($data['product_id']);
        $available = 0;
        if ($product) {
            if ($this->stock->branchStockEnabled($biz)) {
                $available = (int) (BranchStock::where('branch_id', $data['from_branch_id'])
                    ->where('product_id', $product->id)->value('qty') ?? 0);
            } elseif ((int) $data['from_branch_id'] === (int) $this->stock->defaultBranchId($biz)) {
                $available = (int) $product->stock_qty;
            }
        }
        if (! $product || (int) $data['qty'] > $available) {
            return back()->with('error', __('Not enough stock in the source branch - only :available available.', ['available' => $available]));
        }
        $transfer = StockTransfer::create(['business_id' => $biz, 'user_id' => $request->user()->id, 'status' => 'sent'] + $data);
        $this->stock->applyTransfer($transfer, $request->user());

        return back()->with('success', 'Transfer sent');
    }

    public function updateTransfer(Request $request, StockTransfer $transfer)
    {
        abort_unless($transfer->business_id === $this->biz($request), 403);
        $data = $request->validate(['status' => ['required', 'string', 'in:received,cancelled']]);
        if ($data['status'] === 'cancelled' && $transfer->status === 'sent') {
            // reverse the branch movement
            $reverse = $transfer->replicate();
            $reverse->from_branch_id = $transfer->to_branch_id;
            $reverse->to_branch_id = $transfer->from_branch_id;
            $this->stock->applyTransfer($reverse, $request->user());
        }
        $transfer->update(['status' => $data['status']]);

        return back()->with('success', 'Transfer updated');
    }

    // ---------------- Warranty ----------------

    public function warranty(Request $request)
    {
        $biz = $this->biz($request);
        $q = WarrantyClaim::where('business_id', $biz);
        if ($s = $request->query('search')) {
            $q->where(fn ($w) => $w->where('claim_no', 'like', "%{$s}%")->orWhere('customer_name', 'like', "%{$s}%")
                ->orWhere('customer_phone', 'like', "%{$s}%")->orWhere('serial_no', 'like', "%{$s}%")
                ->orWhere('invoice_no', 'like', "%{$s}%"));
        }
        if ($st = $request->query('status')) {
            if ($st === 'overdue') {
                $q->whereNotIn('status', ['closed', 'rejected', 'cancelled'])->whereNotNull('due_date')->where('due_date', '<', DhakaTime::today());
            } elseif ($st !== 'everything') {
                $q->where('status', $st);
            }
        }
        $claims = $q->orderByDesc('id')->paginate(20)->withQueryString();

        $defaults = [
            'types' => ['Brand Warranty', 'Service Warranty'],
            'resolutions' => ['Repaired', 'Replaced', 'Refunded', 'No Fault Found'],
            'reject_reasons' => ['Physical Damage', 'Water Damage', 'Warranty Expired', 'Unauthorized Repair', 'No Defect Found'],
            'cancel_reasons' => ['Customer Withdrew', 'Duplicate Claim', 'Wrong Product', 'Settled Outside'],
            'turnaround_days' => 7,
            'default_period' => 'No Warranty',
            'default_type' => 'Repair',
            'terms' => '',
        ];
        $vocab = [];
        foreach ($defaults as $k => $v) {
            $vocab[$k] = $this->settings->bizGet($biz, "warranty.{$biz}.{$k}", 'warranty.' . $k, $v);
        }

        return Inertia::render('Inventory/Warranty', [
            'claims' => $claims->through(fn ($c) => [
                'id' => $c->id, 'claim_no' => $c->claim_no, 'product_id' => $c->product_id,
                'product_name' => $c->product_id ? Product::find($c->product_id)?->name : null,
                'customer_name' => $c->customer_name, 'customer_phone' => $c->customer_phone,
                'serial_no' => $c->serial_no, 'invoice_no' => $c->invoice_no, 'issue' => $c->issue,
                'type' => $c->type, 'status' => $c->status, 'resolution' => $c->resolution,
                'reject_reason' => $c->reject_reason, 'cancel_reason' => $c->cancel_reason,
                'received_date' => $c->received_date, 'due_date' => $c->due_date, 'closed_date' => $c->closed_date,
                'overdue' => $c->due_date && $c->due_date < DhakaTime::today() && ! in_array($c->status, ['closed', 'rejected', 'cancelled'], true),
                'note' => $c->note,
            ]),
            'counts' => [
                'total' => WarrantyClaim::where('business_id', $biz)->count(),
                'open' => WarrantyClaim::where('business_id', $biz)->whereNotIn('status', ['closed', 'rejected', 'cancelled'])->count(),
                'overdue' => WarrantyClaim::where('business_id', $biz)->whereNotIn('status', ['closed', 'rejected', 'cancelled'])->whereNotNull('due_date')->where('due_date', '<', DhakaTime::today())->count(),
                'closed' => WarrantyClaim::where('business_id', $biz)->where('status', 'closed')->count(),
            ],
            'products' => Product::where('business_id', $biz)->orderBy('name')->get(['id', 'name', 'sku']),
            'vocab' => $vocab,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function storeClaim(Request $request)
    {
        $biz = $this->biz($request);
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'serial_no' => ['nullable', 'string', 'max:120'],
            'invoice_no' => ['nullable', 'string', 'max:120'],
            'issue' => ['required', 'string'],
            'type' => ['nullable', 'string', 'max:120'],
            'received_date' => ['nullable', 'date'],
        ]);
        $days = (int) $this->settings->bizGet($biz, "warranty.{$biz}.turnaround_days", 'warranty.turnaround_days', 7);
        $received = $data['received_date'] ?? DhakaTime::today();
        $count = WarrantyClaim::where('business_id', $biz)->count();
        WarrantyClaim::create(['business_id' => $biz,
            'claim_no' => 'WC-' . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT),
            'status' => 'received', 'received_date' => $received,
            'due_date' => date('Y-m-d', strtotime($received . " +{$days} days")),
        ] + $data);

        return back()->with('success', 'Warranty claim created');
    }

    public function updateClaim(Request $request, WarrantyClaim $claim)
    {
        abort_unless($claim->business_id === $this->biz($request), 403);
        $data = $request->validate([
            'status' => ['required', 'string', 'in:received,in_repair,sent_to_supplier,back_from_supplier,ready_for_pickup,closed,rejected,cancelled'],
            'resolution' => ['nullable', 'string', 'max:120'],
            'reject_reason' => ['nullable', 'string', 'max:120'],
            'cancel_reason' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string'],
        ]);
        if (in_array($data['status'], ['closed', 'rejected', 'cancelled'], true)) {
            $data['closed_date'] = DhakaTime::today();
        }
        $claim->update($data);

        return back()->with('success', 'Claim updated');
    }

    public function saveWarrantySettings(Request $request)
    {
        $data = $request->validate([
            'types' => ['array'], 'resolutions' => ['array'], 'reject_reasons' => ['array'],
            'cancel_reasons' => ['array'], 'turnaround_days' => ['integer', 'min:1', 'max:90'],
            'default_period' => ['nullable', 'string', 'max:60'], 'default_type' => ['nullable', 'string', 'max:60'],
            'terms' => ['nullable', 'string'],
        ]);
        $biz = $this->biz($request);
        foreach (['types', 'resolutions', 'reject_reasons', 'cancel_reasons'] as $k) {
            if (isset($data[$k])) {
                $this->settings->set("warranty.{$biz}.{$k}", array_values(array_filter($data[$k])), $request->user()?->id);
            }
        }
        foreach (['default_period', 'default_type', 'terms'] as $k) {
            if (isset($data[$k])) {
                $this->settings->set("warranty.{$biz}.{$k}", $data[$k], $request->user()?->id);
            }
        }
        if (isset($data['turnaround_days'])) {
            $this->settings->set("warranty.{$biz}.turnaround_days", (int) $data['turnaround_days'], $request->user()?->id);
        }

        return back()->with('success', 'Warranty settings saved');
    }
}
