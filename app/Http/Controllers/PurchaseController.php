<?php

namespace App\Http\Controllers;

use App\Models\FinanceAccount;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseAttachment;
use App\Models\PurchaseItem;
use App\Models\PurchasePayment;
use App\Models\Supplier;
use App\Services\PurchaseService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M5 Purchase — three pages (Dashboard / Purchases / Suppliers) carrying
 * every function of EcomDrive's seven Purchase menus. All figures come
 * from PurchaseService so the pages can never disagree with each other.
 */
class PurchaseController extends Controller
{
    public function __construct(private PurchaseService $svc) {}

    private function biz(Request $request): int
    {
        return \App\Support\ShopContext::id($request);
    }

    private function today(): string
    {
        return now('Asia/Dhaka')->toDateString();
    }

    private function accounts(int $biz): array
    {
        return FinanceAccount::where('business_id', $biz)->where('active', true)
            ->orderBy('name')->get(['id', 'name', 'type'])
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'type' => $a->type])->all();
    }

    private function productOptions(int $biz): array
    {
        return Product::where('business_id', $biz)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'sku', 'cost', 'stock_qty', 'reserved_qty', 'image', 'alert_qty'])
            ->map(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku,
                // Stock is AVAILABLE (on hand minus reserved), the same
                // figure the New Order picker shows - raw stock_qty here
                // made the two pickers disagree by the reserved amount.
                'cost' => (float) $p->cost, 'stock' => $p->available(),
                // The shared photo resolution; Storage::url() built an
                // APP_URL absolute URL that broke off-origin.
                'image' => $p->imageUrl(),
                'alert_qty' => (int) $p->alert_qty,
            ])->all();
    }

    private function supplierOptions(int $biz): array
    {
        return Supplier::where('business_id', $biz)->orderBy('name')->get(['id', 'name', 'phone'])
            ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name, 'phone' => $s->phone])->all();
    }

    // ---------- Purchase Dashboard ----------

    public function dashboard(Request $request): Response
    {
        $biz = $this->biz($request);
        $today = Carbon::parse($this->today());
        $period = in_array($request->query('period'), ['today', 'yesterday', '30d', 'month'], true)
            ? $request->query('period') : 'month';
        $supplierId = $request->query('supplier') ? (int) $request->query('supplier') : null;

        [$from, $to] = match ($period) {
            'today' => [$today->copy(), $today->copy()],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            '30d' => [$today->copy()->subDays(29), $today->copy()],
            default => [$today->copy()->startOfMonth(), $today->copy()],
        };

        $periodPurchases = Purchase::where('business_id', $biz)
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->with('items')->get();
        $bills = $periodPurchases->where('kind', 'bill');
        $periodIds = $periodPurchases->pluck('id');
        $paidOnPeriod = (float) PurchasePayment::where('business_id', $biz)
            ->whereIn('purchase_id', $periodIds)->sum('amount');
        $receivedValue = round((float) $periodPurchases->sum(
            fn (Purchase $p) => $p->items->sum(fn (PurchaseItem $i) => $i->qty_received * (float) $i->unit_cost)
        ), 2);

        $topSuppliers = $bills->groupBy('supplier_id')->map(function ($rows, $sid) {
            $s = $rows->first()->supplier;

            return ['id' => (int) $sid, 'name' => $s?->name ?? '', 'amount' => round((float) $rows->sum('total'), 2), 'purchases' => $rows->count()];
        })->sortByDesc('amount')->take(5)->values()->all();

        $mostBought = PurchaseItem::where('business_id', $biz)
            ->whereIn('purchase_id', $periodIds)->with('product')
            ->get()->groupBy('product_id')->map(function ($rows) {
                $p = $rows->first()->product;

                return [
                    'name' => $p?->name ?? '', 'sku' => $p?->sku ?? '',
                    'qty' => (int) $rows->sum('qty_ordered'),
                    'amount' => round((float) $rows->sum(fn (PurchaseItem $i) => $i->qty_ordered * (float) $i->unit_cost), 2),
                ];
            })->sortByDesc('qty')->take(5)->values()->all();

        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $today->copy()->subMonths($i);
            $sum = (float) Purchase::where('business_id', $biz)
                ->whereYear('date', $m->year)->whereMonth('date', $m->month)->sum('total');
            $months[] = ['label' => $m->format('M'), 'amount' => round($sum, 2)];
        }

        $recent = Purchase::where('business_id', $biz)->with(['supplier', 'items'])
            ->orderByDesc('date')->orderByDesc('id')->limit(8)->get()
            ->map(fn (Purchase $p) => [
                'id' => $p->id,
                'title' => $p->kind === 'quick' ? 'Quick Stock In' : ($p->supplier?->name ?? ''),
                'date' => $p->date->toDateString(),
                'amount' => (float) $p->total,
                'status' => $p->receiveStatus(),
            ])->all();

        $overdue = $this->svc->overdueTotals($biz);
        $overdueRows = array_map(fn ($r) => [
            'supplier_id' => $r['supplier']->id,
            'name' => $r['supplier']->name,
            'overdue' => $r['overdue'],
            'bills' => $r['bills_due'],
            'oldest_due_date' => $r['oldest_due_date'],
        ], $overdue['rows']);

        return Inertia::render('Purchase/Dashboard', [
            'rightNow' => [
                'owed' => $this->svc->totalDue($biz),
                'overdue' => $overdue['amount'],
                'overdueSuppliers' => $overdue['suppliers'],
                'goodsNotReceived' => $this->svc->toReceiveLineCount($biz),
                'goodsNotReceivedValue' => $this->svc->notReceivedValue($biz),
                'lowStock' => $this->svc->reorderProducts($biz)->count(),
            ],
            'periodKey' => $period,
            'supplierFilter' => $supplierId,
            'suppliers' => $this->supplierOptions($biz),
            'period' => [
                'purchased' => round((float) $bills->sum('total'), 2),
                'purchasesCount' => $bills->count(),
                'paid' => round($paidOnPeriod, 2),
                'receivedValue' => $receivedValue,
            ],
            'topSuppliers' => $topSuppliers,
            'mostBought' => $mostBought,
            'months' => $months,
            'recent' => $recent,
            'overdueRows' => $overdueRows,
        ]);
    }

    // ---------- Purchases (List + Receive + New) ----------

    public function index(Request $request): Response
    {
        $biz = $this->biz($request);
        $tab = in_array($request->query('tab'), ['all', 'receive', 'pay', 'quick'], true)
            ? $request->query('tab') : 'all';
        $q = trim((string) $request->query('q', ''));

        $bills = $this->svc->billsWithDue($biz);
        $dueById = $bills->keyBy('id');
        $counts = [
            'all' => Purchase::where('business_id', $biz)->count(),
            'receive' => $this->svc->toReceivePurchaseCount($biz),
            'pay' => $bills->where('due_amount', '>', 0)->count(),
            'payDue' => round((float) $bills->sum('due_amount'), 2),
            'quick' => Purchase::where('business_id', $biz)->where('kind', 'quick')->count(),
        ];

        $props = [
            'tab' => $tab, 'q' => $q, 'counts' => $counts,
            'accounts' => $this->accounts($biz),
            'rows' => [], 'lineRows' => [], 'pager' => null,
        ];

        if ($tab === 'receive') {
            $items = PurchaseItem::where('business_id', $biz)
                ->whereRaw('qty_received + qty_damaged < qty_ordered')
                ->with(['purchase.supplier', 'product'])
                ->when($q !== '', function ($query) use ($q) {
                    $query->whereHas('product', fn ($p) => $p->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%"))
                        ->orWhereHas('purchase.supplier', fn ($s) => $s->where('name', 'like', "%{$q}%"));
                })
                ->orderBy('purchase_id')->paginate(25)->withQueryString();
            $props['lineRows'] = collect($items->items())->map(function (PurchaseItem $i) {
                $ordered = $i->purchase->date;

                return [
                    'item_id' => $i->id, 'purchase_id' => $i->purchase_id,
                    'ordered_on' => $ordered->toDateString(),
                    'waiting_days' => (int) $ordered->diffInDays(Carbon::parse($this->today())),
                    'supplier' => $i->purchase->kind === 'quick' ? null : ($i->purchase->supplier?->name ?? ''),
                    'quick' => $i->purchase->kind === 'quick',
                    'product' => $i->product?->name ?? '',
                    'ordered' => $i->qty_ordered, 'received' => $i->qty_received,
                    'damaged' => $i->qty_damaged, 'left' => $i->left(),
                    'status' => 'Not received',
                ];
            })->all();
            $props['pager'] = ['page' => $items->currentPage(), 'pages' => $items->lastPage(), 'total' => $items->total()];

            return Inertia::render('Purchase/Index', $props);
        }

        $query = Purchase::where('business_id', $biz)->with(['supplier', 'items.product'])
            ->orderByDesc('date')->orderByDesc('id');
        if ($tab === 'quick') {
            $query->where('kind', 'quick');
        } elseif ($tab === 'pay') {
            $query->whereIn('id', $bills->where('due_amount', '>', 0)->pluck('id')->all() ?: [0]);
        }
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->whereHas('supplier', fn ($s) => $s->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('items.product', fn ($p) => $p->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "%{$q}%"));
            });
        }
        $page = $query->paginate(25)->withQueryString();
        $props['rows'] = collect($page->items())->map(function (Purchase $p) use ($dueById) {
            $due = $p->kind === 'bill' ? (float) ($dueById[$p->id]->due_amount ?? 0) : 0.0;
            $paid = $p->kind === 'bill' && $due <= 0 && (float) $p->total > 0;

            return [
                'id' => $p->id, 'date' => $p->date->toDateString(),
                'kind' => $p->kind,
                'supplier' => $p->supplier ? ['name' => $p->supplier->name, 'phone' => $p->supplier->phone] : null,
                'items' => $p->items->map(fn (PurchaseItem $i) => $i->product?->name ?? '')->filter()->implode(', '),
                'amount' => (float) $p->total,
                'due' => $due, 'paid' => $paid,
                'status' => $p->receiveStatus(),
                'can_receive' => ! $p->isFullyReceived(),
                'can_pay' => $p->kind === 'bill' && $due > 0,
                'can_delete' => $p->payments()->count() === 0
                    && (int) $p->items()->sum('qty_received') === 0
                    && (int) $p->items()->sum('qty_damaged') === 0,
            ];
        })->all();
        $props['pager'] = ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()];

        return Inertia::render('Purchase/Index', $props);
    }

    public function create(Request $request): Response
    {
        $biz = $this->biz($request);

        return Inertia::render('Purchase/Create', [
            'products' => $this->productOptions($biz),
            'suppliers' => $this->supplierOptions($biz),
            'today' => $this->today(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $data = $request->validate([
            'supplier_id' => 'required|integer',
            'date' => 'required|date',
            'note' => 'nullable|string|max:2000',
            'tags' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:5120|mimes:jpg,jpeg,png,gif,webp,pdf',
        ]);
        $supplier = Supplier::where('business_id', $biz)->findOrFail($data['supplier_id']);
        foreach ($data['items'] as $l) {
            Product::where('business_id', $biz)->findOrFail($l['product_id']);
        }

        $purchase = $this->svc->createBill($biz, [
            'supplier_id' => $supplier->id, 'date' => $data['date'],
            'note' => $data['note'] ?? null, 'tags' => $data['tags'] ?? null,
        ], $data['items'], $request->user());

        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store('purchase-attachments', 'public');
            PurchaseAttachment::create([
                'purchase_id' => $purchase->id, 'business_id' => $biz,
                'path' => $path, 'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(), 'size' => $file->getSize() ?: 0,
            ]);
        }

        return redirect()->route('purchases.index', ['tab' => 'receive'])
            ->with('success', 'Purchase created.');
    }

    public function quickForm(Request $request): Response
    {
        $biz = $this->biz($request);

        return Inertia::render('Purchase/QuickStock', [
            'products' => $this->productOptions($biz),
            'today' => $this->today(),
        ]);
    }

    public function storeQuick(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $data = $request->validate([
            'date' => 'required|date',
            'note' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ]);
        foreach ($data['items'] as $l) {
            Product::where('business_id', $biz)->findOrFail($l['product_id']);
        }
        $this->svc->createQuickStock($biz, ['date' => $data['date'], 'note' => $data['note'] ?? null], $data['items'], $request->user());

        return redirect()->route('purchases.index', ['tab' => 'quick'])
            ->with('success', 'Stock added.');
    }

    public function show(Request $request, Purchase $purchase): Response
    {
        $biz = $this->biz($request);
        abort_unless($purchase->business_id === $biz, 404);
        $purchase->load(['supplier', 'items.product', 'payments', 'attachments']);

        return Inertia::render('Purchase/Show', [
            'purchase' => [
                'id' => $purchase->id, 'date' => $purchase->date->toDateString(),
                'kind' => $purchase->kind, 'note' => $purchase->note, 'tags' => $purchase->tags,
                'supplier' => $purchase->supplier ? ['name' => $purchase->supplier->name, 'phone' => $purchase->supplier->phone] : null,
                'total' => (float) $purchase->total,
                'paid' => $purchase->paidAmount(),
                'due' => $purchase->due(),
                'status' => $purchase->receiveStatus(),
                'items' => $purchase->items->map(fn (PurchaseItem $i) => [
                    'id' => $i->id, 'product' => $i->product?->name ?? '', 'sku' => $i->product?->sku ?? '',
                    'ordered' => $i->qty_ordered, 'received' => $i->qty_received, 'damaged' => $i->qty_damaged,
                    'left' => $i->left(), 'unit_cost' => (float) $i->unit_cost, 'line_total' => $i->lineTotal(),
                ])->all(),
                'payments' => $purchase->payments->map(fn (PurchasePayment $pay) => [
                    'id' => $pay->id, 'amount' => (float) $pay->amount, 'date' => $pay->date->toDateString(),
                ])->all(),
                'attachments' => $purchase->attachments->map(fn (PurchaseAttachment $a) => [
                    'id' => $a->id, 'name' => $a->original_name,
                    'url' => Storage::disk('public')->url($a->path),
                ])->all(),
            ],
            'accounts' => $this->accounts($biz),
        ]);
    }

    public function receiveForm(Request $request, Purchase $purchase): Response
    {
        $biz = $this->biz($request);
        abort_unless($purchase->business_id === $biz, 404);
        $purchase->load(['supplier', 'items.product']);

        return Inertia::render('Purchase/Receive', [
            'purchase' => [
                'id' => $purchase->id, 'date' => $purchase->date->toDateString(),
                'supplier' => $purchase->supplier?->name ?? '',
                'items' => $purchase->items->map(fn (PurchaseItem $i) => [
                    'id' => $i->id, 'product' => $i->product?->name ?? '',
                    'ordered' => $i->qty_ordered, 'received' => $i->qty_received,
                    'damaged' => $i->qty_damaged, 'left' => $i->left(),
                ])->all(),
            ],
        ]);
    }

    public function storeReceive(Request $request, Purchase $purchase): RedirectResponse
    {
        $biz = $this->biz($request);
        abort_unless($purchase->business_id === $biz, 404);
        $data = $request->validate([
            'lines' => 'required|array',
            'lines.*.received' => 'nullable|integer|min:0',
            'lines.*.damaged' => 'nullable|integer|min:0',
        ]);
        try {
            $this->svc->receive($purchase, $data['lines'], $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('purchases.index', ['tab' => 'receive'])
            ->with('success', 'Goods received.');
    }

    public function payPurchase(Request $request, Purchase $purchase): RedirectResponse
    {
        $biz = $this->biz($request);
        abort_unless($purchase->business_id === $biz && $purchase->kind === 'bill', 404);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|integer',
            'date' => 'required|date',
            'note' => 'nullable|string|max:300',
        ]);
        if ((float) $data['amount'] > $purchase->due() + 0.001) {
            return back()->with('error', 'Amount cannot be more than the due on this purchase.');
        }
        try {
            $this->svc->payPurchase($biz, $purchase, (float) $data['amount'], (int) $data['account_id'], $data['date'], $data['note'] ?? null, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment recorded.');
    }

    public function destroy(Request $request, Purchase $purchase): RedirectResponse
    {
        $biz = $this->biz($request);
        abort_unless($purchase->business_id === $biz, 404);
        $hasReceived = (int) $purchase->items()->sum('qty_received') > 0
            || (int) $purchase->items()->sum('qty_damaged') > 0;
        if ($hasReceived || $purchase->payments()->exists()) {
            return back()->with('error', 'This purchase cannot be deleted - goods were received or a payment was made against it.');
        }
        foreach ($purchase->attachments as $a) {
            Storage::disk('public')->delete($a->path);
        }
        $purchase->attachments()->delete();
        $purchase->items()->delete();
        $purchase->delete();

        return back()->with('success', 'Purchase deleted.');
    }

    // ---------- Suppliers (+ Ledger merged) ----------

    public function suppliers(Request $request): Response
    {
        $biz = $this->biz($request);
        $tab = in_array($request->query('tab'), ['suppliers', 'reorder', 'report'], true)
            ? $request->query('tab') : 'suppliers';
        $q = trim((string) $request->query('q', ''));
        $summaries = $this->svc->supplierSummaries($biz);
        $overdue = $this->svc->overdueTotals($biz);
        $reorder = $this->svc->reorderProducts($biz);

        $rows = collect($summaries)
            ->when($q !== '', fn ($c) => $c->filter(fn ($r) => str_contains(strtolower($r['supplier']->name), strtolower($q))
                || str_contains((string) ($r['supplier']->phone ?? ''), $q)))
            ->map(fn ($r) => [
                'id' => $r['supplier']->id, 'name' => $r['supplier']->name,
                'initials' => $r['supplier']->initials(),
                'phone' => $r['supplier']->phone, 'email' => $r['supplier']->email,
                'address' => $r['supplier']->address, 'note' => $r['supplier']->note,
                'terms_days' => (int) $r['supplier']->terms_days,
                'purchases' => $r['purchases_count'],
                'total_purchased' => $r['total_purchased'],
                'due' => $r['due'], 'bills_due' => $r['bills_due'],
                'overdue' => $r['overdue'],
                'oldest_due_date' => $r['oldest_due_date'],
                'last_purchase' => $r['last_purchase'],
                'last_activity' => $r['last_activity'],
                'has_purchases' => $r['purchases_count'] > 0,
            ])->values()->all();

        // Reorder rows: last supplier + last cost per low product.
        $reorderRows = $reorder->map(function (Product $p) {
            $last = PurchaseItem::where('product_id', $p->id)->with('purchase.supplier')
                ->orderByDesc('id')->first();

            return [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku,
                'stock' => (int) $p->stock_qty, 'alert_qty' => (int) $p->alert_qty,
                'supplier' => $last?->purchase?->supplier?->name ?? '',
                'last_cost' => $last ? (float) $last->unit_cost : (float) $p->cost,
                'suggested' => max(1, (int) $p->alert_qty * 2 - (int) $p->stock_qty),
            ];
        })->all();

        // Purchase Report: per-supplier totals for the chosen period.
        $from = $request->query('from') ?: Carbon::parse($this->today())->startOfMonth()->toDateString();
        $to = $request->query('to') ?: $this->today();
        $reportRows = collect($summaries)->map(function ($r) use ($biz, $from, $to) {
            $purchased = (float) Purchase::where('business_id', $biz)->where('supplier_id', $r['supplier']->id)
                ->where('kind', 'bill')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum('total');
            $paid = (float) PurchasePayment::where('business_id', $biz)->where('supplier_id', $r['supplier']->id)
                ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->sum('amount');

            return [
                'id' => $r['supplier']->id, 'name' => $r['supplier']->name,
                'purchased' => round($purchased, 2), 'paid' => round($paid, 2), 'due' => $r['due'],
            ];
        })->filter(fn ($r) => $r['purchased'] > 0 || $r['paid'] > 0 || $r['due'] > 0)->values()->all();

        return Inertia::render('Purchase/Suppliers', [
            'tab' => $tab, 'q' => $q,
            'stats' => [
                'overdueSuppliers' => $overdue['suppliers'],
                'overdueAmount' => $overdue['amount'],
                'reorderCount' => $reorder->count(),
                'totalDue' => $this->svc->totalDue($biz),
                'suppliersWithDue' => collect($summaries)->where('due', '>', 0)->count(),
            ],
            'rows' => $rows,
            'reorderRows' => $reorderRows,
            'reportRows' => $reportRows,
            'reportFrom' => $from, 'reportTo' => $to,
            'accounts' => $this->accounts($biz),
            'today' => $this->today(),
        ]);
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        $biz = $this->biz($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:120',
            'address' => 'nullable|string|max:500',
            'terms_days' => 'nullable|integer|min:0|max:365',
            'note' => 'nullable|string|max:1000',
        ]);
        Supplier::create([
            'business_id' => $biz, 'name' => $data['name'],
            'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'terms_days' => $data['terms_days'] ?? 30, 'note' => $data['note'] ?? null,
        ]);

        return back()->with('success', 'Supplier added.');
    }

    public function updateSupplier(Request $request, Supplier $supplier): RedirectResponse
    {
        $biz = $this->biz($request);
        abort_unless($supplier->business_id === $biz, 404);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:120',
            'address' => 'nullable|string|max:500',
            'terms_days' => 'nullable|integer|min:0|max:365',
            'note' => 'nullable|string|max:1000',
        ]);
        $supplier->update([
            'name' => $data['name'], 'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null,
            'terms_days' => $data['terms_days'] ?? 30, 'note' => $data['note'] ?? null,
        ]);

        return back()->with('success', 'Supplier updated.');
    }

    public function destroySupplier(Request $request, Supplier $supplier): RedirectResponse
    {
        $biz = $this->biz($request);
        abort_unless($supplier->business_id === $biz, 404);
        if ($supplier->purchases()->exists()) {
            return back()->with('error', 'This supplier cannot be deleted - purchases exist against it.');
        }
        $supplier->delete();

        return back()->with('success', 'Supplier deleted.');
    }

    public function paySupplier(Request $request, Supplier $supplier): RedirectResponse
    {
        $biz = $this->biz($request);
        abort_unless($supplier->business_id === $biz, 404);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|integer',
            'date' => 'required|date',
            'note' => 'nullable|string|max:300',
        ]);
        try {
            $this->svc->paySupplier($biz, $supplier, (float) $data['amount'], (int) $data['account_id'], $data['date'], $data['note'] ?? null, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment recorded.');
    }
}
