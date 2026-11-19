<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\CallLog;
use App\Models\DeletionLog;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\OrderSource;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PhoneNormalizer;
use App\Services\SettingsService;
use App\Services\SuccessRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly SettingsService $settings,
        private readonly SuccessRateService $fraud,
    ) {}

    private function businessId(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    private function own(Request $request, Order $order): Order
    {
        abort_unless($order->business_id === $this->businessId($request), 404);

        return $order;
    }

    private function row(Order $o, ?array $liveFraud = null): array
    {
        $fraud = $liveFraud ?? ['rate' => $o->fraud_rate, 'verdict' => $o->fraud_verdict, 'note' => $o->fraud_note];
        return [
            'id' => $o->id,
            'invoice' => $o->invoice_no,
            'created' => $o->created_at?->format('d M Y'),
            'created_time' => $o->created_at?->format('h:i A'),
            'name' => $o->customer_name,
            'phone' => $o->customer_phone,
            'address' => $o->customer_address,
            'items' => $o->items->map(fn ($i) => ['name' => $i->name, 'qty' => $i->qty, 'price' => (float) $i->price])->values(),
            'items_text' => $o->items->map(fn ($i) => $i->name.($i->qty > 1 ? " ×{$i->qty}" : ''))->implode(', '),
            'total' => (float) $o->grand_total,
            'fraud' => $fraud,
            'tags' => $o->tags ?? [],
            'site' => $o->site,
            'source' => $o->source,
            'status' => $o->status,
            'auto_call' => $o->auto_call_status,
            'pbx_calls' => (int) ($o->pbx_calls_count ?? 0),
            'processed_by' => $o->processor?->name,
            'is_preorder' => (bool) $o->is_preorder,
        ];
    }

    public function index(Request $request): Response
    {
        $biz = $this->businessId($request);
        $status = $request->query('status', 'All');
        $search = trim((string) $request->query('search', ''));
        $perPage = in_array((int) $request->query('per_page', 20), [20, 50, 100], true) ? (int) $request->query('per_page', 20) : 20;

        $base = Order::where('business_id', $biz);
        $counts = ['All' => (clone $base)->count()];
        foreach (array_merge(Order::STATUSES, Order::EXTRA_STATUSES) as $s) {
            $counts[$s] = (clone $base)->where('status', $s)->count();
        }

        $query = (clone $base)->with(['items', 'processor'])->latest();
        $this->applyListFilters($query, $request, $status, $search);
        $page = $query->paginate($perPage)->withQueryString();

        $fraudByPhone = collect($page->items())->pluck('customer_phone')->unique()
            ->mapWithKeys(fn ($phone) => [$phone => $this->fraud->forPhone($biz, $phone)]);
        $callCounts = CallLog::where('business_id', $biz)
            ->whereIn('order_id', collect($page->items())->pluck('id'))
            ->selectRaw('order_id, count(*) as c')->groupBy('order_id')->pluck('c', 'order_id');
        foreach ($page->items() as $o) {
            $o->pbx_calls_count = (int) ($callCounts[$o->id] ?? 0);
        }

        return Inertia::render('Orders/Index', [
            'orders' => $page->items(),
            'rows' => collect($page->items())->map(fn ($o) => $this->row($o, $fraudByPhone[$o->customer_phone] ?? null)),
            'counts' => $counts,
            'filters' => ['status' => $status, 'search' => $search, 'per_page' => $perPage,
                'from' => $request->query('from', ''), 'to' => $request->query('to', ''),
                'source' => $request->query('source', ''), 'processed_by' => $request->query('processed_by', ''),
                'product_code' => $request->query('product_code', ''), 'tag' => $request->query('tag', '')],
            'filterOptions' => [
                'sources' => OrderSource::where('business_id', $biz)->where('is_visible', true)->orderBy('sort')->get(['code', 'label']),
                'staff' => User::where('business_id', $biz)->orderBy('name')->get(['id', 'name']),
            ],
            'pagination' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total()],
            'pendingCount' => $counts['Pending'] ?? 0,
        ]);
    }

    private function applyListFilters($query, Request $request, string $status, string $search): void
    {
        if ($status !== 'All') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $digits = preg_replace('/\D+/', '', $search);
            // Phone matching only for phone-like queries (digits, +, spaces, dashes).
            // An invoice term like "OB312" must not match orders whose phone merely
            // contains those digits (e.g. 01312349876 contains "312").
            $phoneLike = (bool) preg_match('/^[\d+\s-]+$/', $search);
            $query->where(function ($q) use ($search, $digits, $phoneLike) {
                $q->where('invoice_no', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
                if ($phoneLike && strlen($digits) >= 3) {
                    $q->orWhere('customer_phone', 'like', "%{$digits}%");
                }
            });
        }
        if ($from = $request->query('from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('created_at', '<=', $to);
        }
        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }
        if ($by = $request->query('processed_by')) {
            $query->where('processed_by', (int) $by);
        }
        if ($code = trim((string) $request->query('product_code', ''))) {
            $query->whereHas('items', fn ($q) => $q->where('sku', 'like', "%{$code}%")->orWhere('name', 'like', "%{$code}%"));
        }
        if ($tag = trim((string) $request->query('tag', ''))) {
            $query->where('tags', 'like', '%"'.$tag.'"%');
        }
    }

    public function export(Request $request)
    {
        $biz = $this->businessId($request);
        $status = $request->query('status', 'All');
        $search = trim((string) $request->query('search', ''));
        $query = Order::where('business_id', $biz)->with(['items', 'processor'])->latest();
        $this->applyListFilters($query, $request, is_string($status) ? $status : 'All', $search);
        $orders = $query->limit(5000)->get();

        return response()->streamDownload(function () use ($orders) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Invoice', 'Date', 'Customer', 'Phone', 'Address', 'District', 'Source', 'Status', 'Items', 'Subtotal', 'Discount', 'Delivery Charge', 'Advance', 'Due', 'Grand Total', 'Processed By']);
            foreach ($orders as $o) {
                fputcsv($out, [$o->invoice_no, $o->created_at?->format('d M Y h:i A'), $o->customer_name, $o->customer_phone,
                    $o->customer_address, $o->district, $o->source, $o->status,
                    $o->items->map(fn ($i) => $i->name.($i->qty > 1 ? " x{$i->qty}" : ''))->implode(', '),
                    $o->subtotal, $o->discount, $o->delivery_charge, $o->advance, $o->due, $o->grand_total, $o->processor?->name]);
            }
            fclose($out);
        }, 'orders-export.csv', ['Content-Type' => 'text/csv']);
    }

    public function next(Request $request): RedirectResponse
    {
        $next = Order::where('business_id', $this->businessId($request))
            ->where('status', 'Pending')->oldest()->first();
        if (! $next) {
            return redirect()->route('orders.index')->with('success', 'No pending orders left');
        }

        return redirect()->route('orders.show', $next);
    }

    public function create(Request $request): Response
    {
        $biz = $this->businessId($request);

        return Inertia::render('Orders/Create', [
            'sources' => OrderSource::where('business_id', $biz)->where('is_visible', true)->orderBy('sort')
                ->get(['code', 'label']),
            'products' => Product::where('business_id', $biz)->where('is_active', true)->orderBy('name')
                ->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku,
                    'price' => (float) $p->price, 'stock' => $p->stock_qty - $p->reserved_qty]),
            'couriers' => $this->settings->get('couriers.methods', []),
            'allTags' => Order::where('business_id', $biz)->pluck('tags')->filter()
                ->flatMap(fn ($t) => is_array($t) ? $t : [])->unique()->values(),
            'paymentMethods' => ['bKash', 'Nagad', 'Rocket', 'Bank', 'Cash on Delivery'],
            'defaults' => [
                'delivery_charge' => (float) $this->settings->get('business.default_delivery_cost', 60),
                'invoice_slug' => (string) $this->settings->get('business.invoice_slug', 'OB'),
            ],
            'toggles' => [
                'show_email' => (bool) $this->settings->get('orders.show_email', true),
                'show_alt_number' => (bool) $this->settings->get('orders.show_alt_number', false),
                'mandatory_source' => (bool) $this->settings->get('orders.mandatory_source', true),
                'mandatory_shipping_note' => (bool) $this->settings->get('orders.mandatory_shipping_note', false),
                'mandatory_advance_txn' => (bool) $this->settings->get('orders.mandatory_advance_txn', true),
                'cross_sale' => (bool) $this->settings->get('orders.cross_sale', true),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $mandatorySource = (bool) $this->settings->get('orders.mandatory_source', true);
        $mandatoryNote = (bool) $this->settings->get('orders.mandatory_shipping_note', false);
        $mandatoryTxn = (bool) $this->settings->get('orders.mandatory_advance_txn', true);

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_phone' => ['required', 'string', 'max:25'],
            'customer_email' => ['nullable', 'string', 'max:120'],
            'alt_phone' => ['nullable', 'string', 'max:25'],
            'customer_address' => ['required', 'string', 'max:500'],
            'district' => ['nullable', 'string', 'max:80'],
            'area' => ['nullable', 'string', 'max:80'],
            'source' => [$mandatorySource ? 'required' : 'nullable', 'string', 'max:40'],
            'delivery_method' => ['nullable', 'string', 'max:120'],
            'shipping_note' => [$mandatoryNote ? 'required' : 'nullable', 'string', 'max:350'],
            'delivery_note' => ['nullable', 'string', 'max:500'],
            'mode' => ['required', 'in:manual,approved,preorder'],
            'preorder_reason' => ['nullable', 'string', 'max:80'],
            'notify_date' => ['nullable', 'date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'advance' => ['nullable', 'numeric', 'min:0'],
            'txn_id' => [$mandatoryTxn && (float) $request->input('advance', 0) > 0 ? 'required' : 'nullable', 'string', 'max:80'],
            'tags' => ['nullable', 'array'],
            'payment_method' => ['nullable', 'string', 'max:60'],
            'reference_link' => ['nullable', 'string', 'max:255'],
            'cross_sale' => ['nullable', 'boolean'],
            'invoice_prefix' => ['nullable', 'string', 'max:20'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string', 'max:160'],
            'items.*.sku' => ['nullable', 'string', 'max:60'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'attachment' => ['nullable', 'file', 'max:5120'],
        ]);

        if (! PhoneNormalizer::isValid($data['customer_phone'])) {
            return back()->withErrors(['customer_phone' => 'Enter a valid mobile number (01XXXXXXXXX)'])->withInput();
        }

        $attachments = [];
        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('order-attachments', 'public');
            $attachments[] = ['name' => $request->file('attachment')->getClientOriginalName(), 'path' => $path, 'url' => Storage::disk('public')->url($path)];
        }

        if ($data['mode'] === 'approved') {
            // Owner rule: creating straight into Approved must respect stock too.
            $short = $this->orders->shortagesForItems($this->businessId($request), $data['items']);
            if ($short) {
                return back()->with('error', 'Not enough stock — '.implode('; ', $short))->withInput();
            }
        }

        $order = $this->orders->create($this->businessId($request), [
            ...$data,
            'status' => $data['mode'] === 'approved' ? 'Processing' : 'Pending',
            'is_preorder' => $data['mode'] === 'preorder',
            'attachments' => $attachments,
            'ip' => $request->ip(),
        ], $request->user(), 'New Order form');

        if ($data['mode'] === 'approved') {
            // Created in Approved mode: run the same approve transaction.
            $order->processed_by = $request->user()->id;
            $order->approved_at = now();
            $order->processing_status = 'Processing';
            $order->save();
            foreach ($order->items as $item) {
                if ($item->product_id) {
                    $product = Product::where('business_id', $order->business_id)->find($item->product_id);
                    if ($product) {
                        $product->increment('reserved_qty', $item->qty);
                        \App\Models\StockLedger::create(['business_id' => $order->business_id, 'product_id' => $product->id, 'order_id' => $order->id, 'type' => 'reserve', 'qty' => $item->qty, 'note' => "Reserved on approve of {$order->invoice_no}"]);
                    }
                }
            }
            $order->logHistory('Pending', 'Processing', $request->user(), 'Created as approved');
        }

        AppNotification::create(['kind' => 'order', 'title' => "New order {$order->invoice_no}",
            'body' => "{$order->customer_name} · ৳".number_format((float) $order->grand_total), 'link' => "/orders/{$order->id}"]);

        return redirect()->route('orders.show', $order)->with('success', "Order {$order->invoice_no} created");
    }

    public function show(Request $request, Order $order): Response
    {
        $this->own($request, $order);
        $order->load(['items', 'processor', 'history', 'activities', 'courierMethod', 'pickupStore']);
        $biz = $this->businessId($request);
        $rate = $this->fraud->forPhone($biz, $order->customer_phone);

        $suggestion = app(\App\Services\SuggestCourierService::class)->suggest($order);

        $perCourier = [];
        foreach (['Steadfast', 'Pathao', 'RedX', 'Carrybee'] as $c) {
            $delivered = Order::where('business_id', $biz)->where('customer_phone', $order->customer_phone)
                ->where('delivery_method', 'like', "{$c}%")->where('status', 'Delivered')->count();
            $returned = Order::where('business_id', $biz)->where('customer_phone', $order->customer_phone)
                ->where('delivery_method', 'like', "{$c}%")->where('status', 'Returned')->count();
            $perCourier[$c] = ($delivered + $returned) > 0 ? (int) round($delivered / ($delivered + $returned) * 100) : null;
        }

        return Inertia::render('Orders/Show', [
            'order' => [
                ...$this->row($order, $rate),
                'email' => $order->customer_email,
                'district' => $order->district,
                'area' => $order->area,
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount,
                'delivery_charge' => (float) $order->delivery_charge,
                'advance' => (float) $order->advance,
                'due' => $order->due,
                'txn_id' => $order->txn_id,
                'alt_phone' => $order->alt_phone,
                'delivery_method' => $order->delivery_method,
                'delivery_note' => $order->delivery_note,
                'shipping_note' => $order->shipping_note,
                'processing_status' => $order->processing_status,
                'cancellation_reason' => $order->cancellation_reason,
                'payment_method' => $order->payment_method,
                'ip' => $order->ip,
                'attachments' => $order->attachments ?? [],
                'approved_at' => $order->approved_at?->format('d M Y, h:i A'),
                'courier_method_id' => $order->courier_method_id,
                'courier_label' => $order->courierMethod?->label(),
                'courier_status' => $order->courier_status,
                'tracking_id' => $order->tracking_id,
                'consignment_id' => $order->consignment_id,
                'booking_status' => $order->booking_status,
                'pickup_store_name' => $order->pickupStore?->name,
                'shipped_at' => $order->shipped_at?->format('d M Y, h:i A'),
                'history' => $order->history->map(fn ($h) => [
                    'text' => $h->note === 'Approved'
                        ? "Approved by {$h->actor_name} — {$h->from_status} → {$h->to_status}"
                        : ($h->from_status ? "Status: {$h->from_status} → {$h->to_status}" : "Order created").($h->note && $h->note !== 'Approved' ? " — {$h->note}" : ''),
                    'time' => $h->created_at?->format('h:i A'),
                    'date' => $h->created_at?->format('d M'),
                ]),
                'activities' => $order->activities->map(fn ($a) => [
                    'kind' => $a->kind, 'text' => $a->text,
                    'time' => $a->created_at?->format('h:i A'), 'date' => $a->created_at?->format('d M'),
                ]),
            ],
            'customer' => [
                'orders' => $rate['orders'], 'delivered' => $rate['delivered'], 'returned' => $rate['returned'],
                'rate' => $rate['rate'], 'verdict' => $rate['verdict'], 'note' => $rate['note'],
                'per_courier' => $perCourier,
                'network' => $rate['network'] ?? [],
                'headline' => $rate['headline'] ?? 'history',
                'is_returning' => $rate['orders'] > 1,
            ],
            'couriers' => $this->settings->get('couriers.methods', []),
            'courierMethods' => \App\Models\CourierMethod::where('business_id', $biz)->where('is_active', true)->with('pickupStores')->orderBy('name')->get()->map(function ($m) use ($order, $suggestion) {
                return ['id' => $m->id, 'name' => $m->name, 'label' => $m->label(), 'type' => $m->courier_type,
                    'class' => $m->courierClass(), 'booking_mode' => $m->bookingMode(),
                    'charge' => $m->chargeFor($order->effectiveDistrict()),
                    'is_default' => $m->is_default, 'is_preferred' => $m->is_preferred,
                    'suggested' => $suggestion && $suggestion['method']->id === $m->id,
                    'suggested_reason' => $suggestion && $suggestion['method']->id === $m->id ? $suggestion['reason'] : null,
                    'stores' => $m->pickupStores->map(fn ($st) => ['id' => $st->id, 'store_id' => $st->store_id, 'name' => $st->name, 'is_default' => $st->is_default])->all()];
            })->all(),
            'sources' => OrderSource::where('business_id', $biz)->where('is_visible', true)->orderBy('sort')->get(['code', 'label']),
            'products' => Product::where('business_id', $biz)->where('is_active', true)->orderBy('name')
                ->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku,
                    'price' => (float) $p->price, 'stock' => $p->stock_qty - $p->reserved_qty]),
            'processingStatuses' => Order::PROCESSING_STATUSES,
            'cancelReasons' => Order::CANCEL_REASONS,
            'pendingCount' => Order::where('business_id', $biz)->where('status', 'Pending')->count(),
        ]);
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->own($request, $order);
        $data = $request->validate([
            'source' => ['nullable', 'string', 'max:40'],
            'processing_status' => ['nullable', 'in:'.implode(',', Order::PROCESSING_STATUSES)],
            'cancellation_reason' => ['nullable', 'in:'.implode(',', Order::CANCEL_REASONS)],
            'delivery_method' => ['nullable', 'string', 'max:120'],
            'delivery_note' => ['nullable', 'string', 'max:500'],
            'shipping_note' => ['nullable', 'string', 'max:350'],
            'tags' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:500'],
            'advance_payment' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (($data['processing_status'] ?? null) === 'Cancel') {
            $this->orders->changeStatus($order, 'Cancelled', $request->user(), $data['cancellation_reason'] ?? 'Customer Request');

            return back()->with('success', "Order {$order->invoice_no} cancelled");
        }

        $oldSource = $order->source;
        $order->fill(array_filter([
            'source' => $data['source'] ?? null,
            'processing_status' => $data['processing_status'] ?? null,
            'delivery_method' => $data['delivery_method'] ?? null,
            'delivery_note' => $data['delivery_note'] ?? null,
            'shipping_note' => $data['shipping_note'] ?? null,
            'tags' => $data['tags'] ?? null,
        ], fn ($v) => $v !== null));

        if (! empty($data['advance_payment'])) {
            $order->advance = (float) $order->advance + (float) $data['advance_payment'];
            $order->logActivity('action', 'Advance payment recorded: ৳'.number_format((float) $data['advance_payment'])." by {$request->user()->name}", $request->user());
        }
        $order->save();
        if (! empty($data['advance_payment'])) {
            app(\App\Services\FinanceService::class)->syncOrderAdvance($order, $request->user());
        }

        if (! empty($data['processing_status'])) {
            $order->logActivity('action', "Processing status set to {$data['processing_status']} by {$request->user()->name}", $request->user());
        }
        if (! empty($data['source']) && $data['source'] !== $oldSource) {
            $order->logActivity('action', "Source changed from {$oldSource} to {$data['source']} by {$request->user()->name}", $request->user());
        }
        if (! empty($data['note'])) {
            $order->logActivity('note', $data['note'], $request->user());
        }

        return back()->with('success', 'Changes saved');
    }

    public function addItem(Request $request, Order $order): RedirectResponse
    {
        $this->own($request, $order);
        if (! in_array($order->status, ['Pending', 'Processing'], true)) {
            return back()->withErrors(['items' => 'Products can only be added while the order is Pending or Processing']);
        }
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'qty' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        $product = Product::where('business_id', $order->business_id)->where('is_active', true)->find($data['product_id']);
        if (! $product) {
            return back()->withErrors(['items' => 'Product not found']);
        }

        $order->items()->create([
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            'qty' => $data['qty'], 'price' => $product->price, 'cost' => $product->cost,
        ]);
        if ($order->status === 'Processing') {
            $product->increment('reserved_qty', $data['qty']);
            \App\Models\StockLedger::create(['business_id' => $order->business_id, 'product_id' => $product->id,
                'order_id' => $order->id, 'type' => 'reserve', 'qty' => $data['qty'],
                'note' => "Reserved for product added to {$order->invoice_no}"]);
        }
        $order->subtotal = $order->items()->get()->sum(fn ($i) => (float) $i->price * $i->qty);
        $order->grand_total = (float) $order->subtotal - (float) $order->discount + (float) $order->delivery_charge;
        $order->save();
        $order->logActivity('action', "Product added: {$product->name} ×{$data['qty']} by {$request->user()->name}", $request->user());

        return back()->with('success', 'Product added to the order');
    }

    public function approve(Request $request, Order $order): RedirectResponse
    {
        $this->own($request, $order);
        if (! in_array($order->status, ['Pending', 'Incomplete'], true)) {
            return back()->with('success', 'This order is already processed');
        }
        try {
            $this->orders->approve($order, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        if ($request->boolean('next')) {
            $next = Order::where('business_id', $order->business_id)->where('status', 'Pending')->oldest()->first();
            if ($next) {
                return redirect()->route('orders.show', $next)->with('success', "Order {$order->invoice_no} approved — next order loaded");
            }

            return redirect()->route('orders.index')->with('success', "Order {$order->invoice_no} approved — no pending orders left");
        }

        return back()->with('success', "Order {$order->invoice_no} approved");
    }

    public function sendSms(Request $request, Order $order): RedirectResponse
    {
        $this->own($request, $order);
        $type = $request->validate(['type' => ['required', 'in:reminder,advance']])['type'];
        $biz = $order->business_id;

        $capDay = (int) $this->settings->get('sms.cap_day', 1);
        $capWeek = (int) $this->settings->get('sms.cap_week', 3);
        $sentToday = \App\Models\OrderActivity::where('kind', 'sms')
            ->whereHas('order', fn ($q) => $q->where('business_id', $biz)->where('customer_phone', $order->customer_phone))
            ->whereDate('created_at', today())->count();
        $sentWeek = \App\Models\OrderActivity::where('kind', 'sms')
            ->whereHas('order', fn ($q) => $q->where('business_id', $biz)->where('customer_phone', $order->customer_phone))
            ->where('created_at', '>=', now()->subDays(7))->count();

        $label = $type === 'reminder' ? 'Reminder SMS' : 'Advance SMS';
        if ($sentToday >= $capDay) {
            $order->logActivity('sms', "{$label} skipped — daily SMS limit reached for this customer", $request->user());

            return back()->with('success', "{$label} skipped — daily limit reached (logged on the order)");
        }
        if ($sentWeek >= $capWeek) {
            $order->logActivity('sms', "{$label} skipped — weekly SMS limit reached for this customer", $request->user());

            return back()->with('success', "{$label} skipped — weekly limit reached (logged on the order)");
        }

        $template = MessageTemplate::where('business_id', $biz)->where('key', $type)->first();
        $body = $template?->body ?? '';
        $rendered = strtr($body, [
            '{name}' => $order->customer_name, '{invoice}' => $order->invoice_no,
            '{amount}' => number_format((float) $order->grand_total), '{advance}' => number_format(max(100, (float) $order->due)),
            '{shop}' => (string) $this->settings->get('business.name', 'Demo Shop'),
        ]);
        $order->logActivity('sms', "{$label} sent to customer — \"{$rendered}\"", $request->user());

        return back()->with('success', "{$label} sent");
    }

    public function block(Request $request, Order $order): RedirectResponse
    {
        $this->own($request, $order);
        $type = $request->validate(['type' => ['required', 'in:ip,phone,device']])['type'];
        $value = $type === 'phone' ? $order->customer_phone : ($type === 'ip' ? ($order->ip ?? 'unknown') : ($order->device ?? 'unknown'));
        \App\Models\IpPhoneBlock::firstOrCreate(
            ['business_id' => $order->business_id, 'type' => $type, 'value' => $value],
            ['reason' => "Blocked from order {$order->invoice_no}", 'added_by' => $request->user()->name]
        );
        if ($type === 'phone') {
            \App\Models\Customer::where('business_id', $order->business_id)->where('phone', $order->customer_phone)
                ->update(['is_blacklisted' => true]);
        }
        $order->logActivity('action', ucfirst($type)." blocked by {$request->user()->name}", $request->user());

        return back()->with('success', ucfirst($type).' blocked');
    }

    public function destroy(Request $request, Order $order): RedirectResponse
    {
        $this->own($request, $order);
        $reason = (string) $request->input('reason', 'Deleted by staff');
        if ($order->status === 'Processing') {
            foreach ($order->items as $item) {
                if ($item->product_id) {
                    $product = Product::where('business_id', $order->business_id)->find($item->product_id);
                    if ($product) {
                        $product->decrement('reserved_qty', $item->qty);
                        \App\Models\StockLedger::create(['business_id' => $order->business_id, 'product_id' => $product->id, 'order_id' => $order->id, 'type' => 'release_reserve', 'qty' => $item->qty, 'note' => "Released on delete of {$order->invoice_no}"]);
                    }
                }
            }
        }
        DeletionLog::create(['business_id' => $order->business_id, 'order_ref' => $order->invoice_no,
            'deleted_by' => $request->user()->name, 'reason' => $reason]);
        $invoice = $order->invoice_no;
        $order->delete();

        return redirect()->route('orders.index')->with('success', "Order {$invoice} deleted");
    }

    public function invoice(Request $request, Order $order): Response
    {
        $this->own($request, $order);
        $order->load('items');

        return Inertia::render('Orders/Invoice', [
            'order' => [...$this->row($order),
                'subtotal' => (float) $order->subtotal, 'discount' => (float) $order->discount,
                'delivery_charge' => (float) $order->delivery_charge, 'advance' => (float) $order->advance,
                'due' => $order->due, 'payment_method' => $order->payment_method],
            'business' => [
                'name' => (string) $this->settings->get('business.name', 'Demo Shop'),
                'mobile' => (string) $this->settings->get('business.mobile', ''),
                'address' => (string) $this->settings->get('business.address', ''),
            ],
            'template' => (string) $this->settings->get('invoice.template', 'a4'),
        ]);
    }
}
