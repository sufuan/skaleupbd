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
use App\Support\DhakaTime;
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
        return \App\Support\ShopContext::id($request);
    }

    private function own(Request $request, Order $order): Order
    {
        abort_unless($order->business_id === $this->businessId($request), 404);

        return $order;
    }

    private function row(Order $o, ?array $liveFraud = null, ?array $sourceLabels = null, ?array $fraudCounts = null): array
    {
        $fraud = $liveFraud ?? ['rate' => $o->fraud_rate, 'verdict' => $o->fraud_verdict, 'note' => $o->fraud_note];
        // The Success cell prints "delivered / orders" for the phone — the
        // stored snapshot never carried those counts, so every row used to
        // render 0/0. The list passes one grouped count per page; the
        // detail page's live check already includes them.
        if ($fraudCounts !== null) {
            $fraud['orders'] = $fraudCounts['orders'];
            $fraud['delivered'] = $fraudCounts['delivered'];
        }
        $sourceLabel = $sourceLabels[$o->source] ?? $o->source;
        return [
            'id' => $o->id,
            'invoice' => $o->invoice_no,
            'created' => DhakaTime::fmt($o->created_at, 'd M Y'),
            'created_time' => DhakaTime::fmt($o->created_at, 'h:i A'),
            'name' => $o->customer_name,
            'phone' => $o->customer_phone,
            'address' => $o->customer_address,
            'items' => $o->items->map(fn ($i) => ['name' => $i->name, 'qty' => $i->qty, 'price' => (float) $i->price])->values(),
            'items_text' => $o->items->map(fn ($i) => $i->name.($i->qty > 1 ? " ×{$i->qty}" : ''))->implode(', '),
            'total' => (float) $o->grand_total,
            'fraud' => $fraud,
            'tags' => $o->tags ?? [],
            'site' => $o->site,
            'site_label' => $o->site ?: $sourceLabel,
            'source' => $o->source,
            'source_label' => $sourceLabel,
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

        // The list must never block on live courier APIs: rows render the
        // fraud snapshot stored on each order (refreshed at intake, on the
        // detail page and at approve). A live network check per phone made
        // this page time out whenever a courier API answered slowly.
        $callCounts = CallLog::where('business_id', $biz)
            ->whereIn('order_id', collect($page->items())->pluck('id'))
            ->selectRaw('order_id, count(*) as c')->groupBy('order_id')->pluck('c', 'order_id');
        foreach ($page->items() as $o) {
            $o->pbx_calls_count = (int) ($callCounts[$o->id] ?? 0);
        }

        // One grouped query for the whole page: each phone's order history
        // (the Success cell's delivered/total), mirroring SuccessRateService.
        $pagePhones = collect($page->items())->pluck('customer_phone')->filter()->unique()->values();
        $fraudCounts = [];
        if ($pagePhones->isNotEmpty()) {
            $fc = Order::where('business_id', $biz)->whereIn('customer_phone', $pagePhones)
                ->selectRaw("customer_phone, count(*) as total, sum(case when status = 'Delivered' then 1 else 0 end) as delivered")
                ->groupBy('customer_phone')->get();
            foreach ($fc as $r) {
                $fraudCounts[$r->customer_phone] = ['orders' => (int) $r->total, 'delivered' => (int) $r->delivered];
            }
        }
        $sourceLabels = OrderSource::where('business_id', $biz)->pluck('label', 'code')->all();

        return Inertia::render('Orders/Index', [
            'orders' => $page->items(),
            'rows' => collect($page->items())->map(fn ($o) => $this->row($o, null, $sourceLabels, $fraudCounts[$o->customer_phone] ?? ['orders' => 0, 'delivered' => 0])),
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
                fputcsv($out, [$o->invoice_no, DhakaTime::fmt($o->created_at, 'd M Y h:i A'), $o->customer_name, $o->customer_phone,
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
            return redirect()->route('orders.index')->with('success', __('No pending orders left'));
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
                    'price' => (float) $p->price, 'stock' => $p->available(), 'image' => $p->imageUrl(),
                    // Variant picker (K-H2): the New Order form lets the
                    // seller pick which variant leaves; the line stores
                    // the variant id + name and stock moves on the
                    // variant's own pool.
                    'variants' => $p->activeVariants()->map(fn ($v) => ['id' => $v->id, 'name' => $v->name,
                        'price' => (float) $v->effectivePrice(), 'stock' => $v->available()])->all()]),
            'couriers' => app(\App\Services\DeliveryMethodList::class)->forBusiness($biz),
            'allTags' => Order::where('business_id', $biz)->pluck('tags')->filter()
                ->flatMap(fn ($t) => is_array($t) ? $t : [])->unique()->values(),
            'paymentMethods' => ['bKash', 'Nagad', 'Rocket', 'Bank', 'Cash on Delivery'],
            'defaults' => [
                'delivery_charge' => (float) $this->settings->bizGet($biz, "business.{$biz}.default_delivery_cost", 'business.default_delivery_cost', 60),
                'invoice_slug' => (string) $this->settings->bizGet($biz, "business.{$biz}.invoice_slug", 'business.invoice_slug', 'OB'),
            ],
            'toggles' => [
                'show_email' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.show_email", 'orders.show_email', true),
                'show_alt_number' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.show_alt_number", 'orders.show_alt_number', false),
                'mandatory_source' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.mandatory_source", 'orders.mandatory_source', true),
                'mandatory_shipping_note' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.mandatory_shipping_note", 'orders.mandatory_shipping_note', false),
                'mandatory_advance_txn' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.mandatory_advance_txn", 'orders.mandatory_advance_txn', true),
                'cross_sale' => (bool) $this->settings->bizGet($biz, "orders.{$biz}.cross_sale", 'orders.cross_sale', true),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $storeBiz = $this->businessId($request);
        $mandatorySource = (bool) $this->settings->bizGet($storeBiz, "orders.{$storeBiz}.mandatory_source", 'orders.mandatory_source', true);
        $mandatoryNote = (bool) $this->settings->bizGet($storeBiz, "orders.{$storeBiz}.mandatory_shipping_note", 'orders.mandatory_shipping_note', false);
        $mandatoryTxn = (bool) $this->settings->bizGet($storeBiz, "orders.{$storeBiz}.mandatory_advance_txn", 'orders.mandatory_advance_txn', true);

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
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.variant_name' => ['nullable', 'string', 'max:160'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'attachment' => ['nullable', 'file', 'max:5120'],
        ]);

        if (! PhoneNormalizer::isValid($data['customer_phone'])) {
            return back()->withErrors(['customer_phone' => 'Enter a valid mobile number (01XXXXXXXXX)'])->withInput();
        }

        // S-F3/S-F4: the discount and the advance are capped by the bill
        // itself. A discount past subtotal + delivery made grand_total
        // negative (a ৳5,000 discount on a ৳710 bill booked -৳4,290),
        // and an advance past the total posted phantom collected income
        // into Finance (৳99,999 on a ৳710 order). The service computes
        // the same subtotal from these very items, so the caps judged
        // here are the caps the stored row will carry.
        $subtotal = collect($data['items'])->sum(fn ($i) => (float) $i['price'] * (int) $i['qty']);
        $discount = (float) ($data['discount'] ?? 0);
        $deliveryCharge = (float) ($data['delivery_charge'] ?? 0);
        if ($discount > $subtotal + $deliveryCharge) {
            return back()->withErrors(['discount' => __('The discount cannot be more than the subtotal plus the delivery charge.')])->withInput();
        }
        if ((float) ($data['advance'] ?? 0) > $subtotal - $discount + $deliveryCharge) {
            return back()->withErrors(['advance' => __('The advance cannot be more than the order total.')])->withInput();
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
                return back()->with('error', __('Not enough stock - :detail', ['detail' => implode('; ', $short)]))->withInput();
            }
        }

        try {
            $order = $this->orders->create($this->businessId($request), [
                ...$data,
                'status' => $data['mode'] === 'approved' ? 'Processing' : 'Pending',
                'is_preorder' => $data['mode'] === 'preorder',
                'attachments' => $attachments,
                'ip' => $request->ip(),
            ], $request->user(), 'New Order form');
        } catch (\App\Exceptions\InsufficientTokensException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        if ($data['mode'] === 'approved') {
            // Created in Approved mode. Stock was already reserved exactly
            // once inside OrderService::create() (the order is created in
            // Processing) - this branch must NOT reserve again (Round 3,
            // H1: the old inline loop here double-reserved every Approved
            // form order and leaked a phantom unit on cancel).
            $order->processed_by = $request->user()->id;
            $order->approved_at = now();
            $order->processing_status = 'Processing';
            $order->save();
            $order->logHistory('Pending', 'Processing', $request->user(), 'Created as approved');
        }

        AppNotification::create(['business_id' => $order->business_id, 'kind' => 'order', 'title' => "New order {$order->invoice_no}",
            'body' => "{$order->customer_name} · ৳".number_format((float) $order->grand_total), 'link' => "/orders/{$order->id}"]);

        return redirect()->route('orders.show', $order)->with('success', __('Order :invoice created', ['invoice' => $order->invoice_no]));
    }

    public function show(Request $request, Order $order): Response
    {
        $this->own($request, $order);
        $order->load(['items', 'processor', 'history', 'activities', 'courierMethod', 'pickupStore']);
        $biz = $this->businessId($request);
        // Cache-only network figures: the detail page renders the stored
        // snapshot plus whatever is already cached and queues the live
        // refresh — it never waits on a courier API either.
        $rate = $this->fraud->forPhone($biz, $order->customer_phone, deferNetwork: true);
        if ($rate['network_pending'] ?? false) {
            \App\Jobs\RefreshFraudCheckJob::dispatch($order->id);
        }

        $suggestion = app(\App\Services\SuggestCourierService::class)->suggest($order);

        // Collected-advance state: on a cancelled order the advance money is
        // still sitting in Finance until the seller refunds it from here.
        $finService = app(\App\Services\FinanceService::class);
        $advanceTxn = $finService->orderAdvanceTxn($order);

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
                ...$this->row($order, $rate, OrderSource::where('business_id', $biz)->pluck('label', 'code')->all()),
                'email' => $order->customer_email,
                'district' => $order->district,
                'area' => $order->area,
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount,
                'delivery_charge' => (float) $order->delivery_charge,
                'advance' => (float) $order->advance,
                'advance_collected' => $advanceTxn !== null,
                'advance_amount' => $advanceTxn ? (float) $advanceTxn->amount : (float) $order->advance,
                'advance_method' => $advanceTxn?->account?->name ?? $order->payment_method,
                'advance_refunded' => $finService->advanceRefunded($order),
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
                'approved_at' => DhakaTime::fmt($order->approved_at, 'd M Y, h:i A'),
                'courier_method_id' => $order->courier_method_id,
                'courier_label' => $order->courierMethod?->label(),
                'courier_status' => $order->courier_status,
                'tracking_id' => $order->tracking_id,
                'consignment_id' => $order->consignment_id,
                'booking_status' => $order->booking_status,
                'pickup_store_name' => $order->pickupStore?->name,
                'shipped_at' => DhakaTime::fmt($order->shipped_at, 'd M Y, h:i A'),
                'history' => $order->history->map(fn ($h) => [
                    'text' => $h->note === 'Approved'
                        ? "Approved by {$h->actor_name} - {$h->from_status} → {$h->to_status}"
                        : ($h->from_status ? "Status: {$h->from_status} → {$h->to_status}" : "Order created").($h->note && $h->note !== 'Approved' ? " - {$h->note}" : ''),
                    'time' => DhakaTime::fmt($h->created_at, 'h:i A'),
                    'date' => DhakaTime::fmt($h->created_at, 'd M'),
                ]),
                'activities' => $order->activities->map(fn ($a) => [
                    'kind' => $a->kind, 'text' => $a->text,
                    'time' => DhakaTime::fmt($a->created_at, 'h:i A'), 'date' => DhakaTime::fmt($a->created_at, 'd M'),
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
            'couriers' => app(\App\Services\DeliveryMethodList::class)->forBusiness($biz),
            'courierMethods' => \App\Models\CourierMethod::where('business_id', $biz)->where('is_active', true)->with('pickupStores')->orderBy('name')->get()->map(function ($m) use ($order, $suggestion) {
                return ['id' => $m->id, 'name' => $m->name, 'label' => $m->label(), 'type' => $m->courier_type,
                    'class' => $m->courierClass(), 'booking_mode' => $m->bookingMode(),
                    'charge' => $m->chargeFor($order->effectiveDistrict()),
                    'is_default' => $m->is_default, 'is_preferred' => $m->is_preferred,
                    'suggested' => $suggestion && $suggestion['method']->id === $m->id,
                    'has_store_id' => ! empty($m->credentials['store_id']),
                    'suggested_reason' => $suggestion && $suggestion['method']->id === $m->id ? $suggestion['reason'] : null,
                    'stores' => $m->pickupStores->map(fn ($st) => ['id' => $st->id, 'store_id' => $st->store_id, 'name' => $st->name, 'is_default' => $st->is_default])->all()];
            })->all(),
            'sources' => OrderSource::where('business_id', $biz)->where('is_visible', true)->orderBy('sort')->get(['code', 'label']),
            'products' => Product::where('business_id', $biz)->where('is_active', true)->orderBy('name')
                ->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku,
                    'price' => (float) $p->price, 'stock' => $p->available()]),
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
            try {
                $this->orders->changeStatus($order, 'Cancelled', $request->user(), $data['cancellation_reason'] ?? 'Customer Request');
            } catch (\Illuminate\Validation\ValidationException $e) {
                // e.g. M4: a Delivered order cannot be cancelled.
                return back()->with('error', collect($e->errors())->flatten()->first());
            }

            return back()->with('success', __('Order :invoice cancelled', ['invoice' => $order->invoice_no]));
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
            // S-F4: the running advance may never pass the order total -
            // the excess would sit in Finance as collected income the
            // customer never owed (and become refundable on cancel).
            if ((float) $order->advance + (float) $data['advance_payment'] > (float) $order->grand_total) {
                return back()->with('error', __('The advance cannot be more than the order total.'));
            }
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

        return back()->with('success', __('Changes saved'));
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
        // R5: on a Processing order the new line reserves immediately
        // (ensureReserved below), so it must pass the same shortage
        // discipline as an approval - judged net of what this order
        // already holds, so its own existing lines never count against
        // it. Without this, Add Item topped the reservation up in full
        // and the later ship drove the row negative.
        if ($order->status === 'Processing') {
            $short = $this->orders->shortagesForAdditionalItems($order, [
                ['product_id' => $product->id, 'qty' => (int) $data['qty']],
            ]);
            if ($short) {
                return back()->withErrors(['items' => 'Not enough stock - '.implode('; ', $short)]);
            }
        }

        $order->items()->create([
            'product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
            // The one cost definition (Round 6, P7/P8): a combo added here
            // books its component sum, not its own (usually 0) cost field.
            'qty' => $data['qty'], 'price' => $product->price, 'cost' => $this->orders->lineUnitCost($product, null),
        ]);
        if ($order->status === 'Processing') {
            // Through the shared exactly-once routine (Round 3, H1): it
            // reserves only the newly added item's shortfall.
            $this->orders->ensureReserved($order->fresh(['items']), $request->user(), "Reserved for product added to {$order->invoice_no}");
        }
        $order->subtotal = $order->items()->get()->sum(fn ($i) => (float) $i->price * $i->qty);
        $order->grand_total = (float) $order->subtotal - (float) $order->discount + (float) $order->delivery_charge;
        $order->save();
        $order->logActivity('action', "Product added: {$product->name} ×{$data['qty']} by {$request->user()->name}", $request->user());

        return back()->with('success', __('Product added to the order'));
    }

    public function approve(Request $request, Order $order): RedirectResponse
    {
        $this->own($request, $order);
        if (! in_array($order->status, ['Pending', 'Incomplete'], true)) {
            return back()->with('success', __('This order is already processed'));
        }
        try {
            $this->orders->approve($order, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        if ($request->boolean('next')) {
            $next = Order::where('business_id', $order->business_id)->where('status', 'Pending')->oldest()->first();
            if ($next) {
                return redirect()->route('orders.show', $next)->with('success', __('Order :invoice approved - next order loaded', ['invoice' => $order->invoice_no]));
            }

            return redirect()->route('orders.index')->with('success', __('Order :invoice approved - no pending orders left', ['invoice' => $order->invoice_no]));
        }

        return back()->with('success', __('Order :invoice approved', ['invoice' => $order->invoice_no]));
    }

    public function sendSms(Request $request, Order $order): RedirectResponse
    {
        $this->own($request, $order);
        $type = $request->validate(['type' => ['required', 'in:reminder,advance']])['type'];
        $biz = $order->business_id;
        $label = $type === 'reminder' ? 'Reminder SMS' : 'Advance SMS';

        $template = MessageTemplate::where('business_id', $biz)->where('key', $type)->first();
        // Substitute the FULL placeholder set the templates use - the
        // automation substitutes {phone} from the shop contact number,
        // and the seeded reminder/advance bodies also carry {phone} /
        // {number}; leaving them raw sent literal braces to customers
        // (who were charged for the send).
        $contact = $this->settings->shopContact($biz, 'mobile');
        $rendered = strtr($template?->body ?? '', [
            '{name}' => $order->customer_name, '{invoice}' => $order->invoice_no,
            '{amount}' => number_format((float) $order->grand_total), '{advance}' => number_format(max(100, (float) $order->due)),
            '{shop}' => $this->settings->shopName($biz),
            '{phone}' => $contact, '{number}' => $contact,
            '{courier}' => $order->delivery_method ?: 'Courier', '{cn}' => $order->consignment_id ?: $order->tracking_id ?: '',
            '{product}' => $order->items->first()?->name ?? '',
        ]);

        // A real send through the one SMS service, under the same flow keys
        // the automation uses — so the do-not-send list, the frequency caps
        // (reminder is a marketing flow) and the prepaid balance all apply,
        // and the attempt lands in sms_logs. The old code only wrote a
        // timeline note and sent nothing.
        $log = app(\App\Services\SmsService::class)->send($biz, $order->customer_phone, $rendered, [
            'type' => 'automation', 'key' => $type, 'label' => $label, 'ref' => $order->invoice_no,
        ], $order->customer_id);

        if ($log->status === 'sent') {
            $order->logActivity('sms', "{$label} sent to customer - \"{$rendered}\"", $request->user());

            return back()->with('success', __(':label sent', ['label' => $label]));
        }

        $reason = match ($log->status) {
            'skipped_dnd' => 'customer is on the do-not-send list',
            'skipped_cap' => 'SMS limit reached for this customer',
            default => $log->error ?: 'SMS could not be sent',
        };
        $order->logActivity('sms', "{$label} not sent - {$reason}", $request->user());

        return back()->with('error', __(':label not sent - :reason', ['label' => $label, 'reason' => $reason]));
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

        return back()->with('success', __(':type blocked', ['type' => ucfirst($type)]));
    }

    /**
     * Refund a cancelled order's collected advance. The money sat in Finance
     * as ordinary income until now; this posts the one reversing expense
     * entry (FinanceService::refundOrderAdvance, idempotent per order).
     */
    public function refundAdvance(Request $request, Order $order): RedirectResponse
    {
        $this->own($request, $order);
        if ($order->status !== 'Cancelled') {
            return back()->with('error', __('Only a cancelled order can have its advance refunded.'));
        }
        try {
            $txn = app(\App\Services\FinanceService::class)->refundOrderAdvance($order, $request->user());
        } catch (\DomainException | \RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        if ($txn->wasRecentlyCreated) {
            $order->logActivity('action', 'Advance refunded: ৳'.number_format((float) $txn->amount)." by {$request->user()->name}", $request->user());
        }

        return back()->with('success', __('Advance refunded for order :invoice', ['invoice' => $order->invoice_no]));
    }

    public function destroy(Request $request, Order $order): RedirectResponse
    {
        $this->own($request, $order);
        // S-F9: an order that has entered fulfilment or settled money
        // must not vanish - Finance revenue/cost/profit are computed
        // from the orders table, so deleting a Delivered order silently
        // rewrote reported history, and deleting a Shipped order erased
        // a parcel that is physically with the courier. Only orders
        // with no stock or money effects (Pending, Cancelled) may be
        // deleted; a Cancelled order already released its reservation
        // and a Pending one never held any.
        if (! in_array($order->status, ['Pending', 'Cancelled'], true)) {
            return back()->with('error', __('Only a Pending or Cancelled order can be deleted.'));
        }
        $reason = (string) $request->input('reason', 'Deleted by staff');
        DeletionLog::create(['business_id' => $order->business_id, 'order_ref' => $order->invoice_no,
            'deleted_by' => $request->user()->name, 'reason' => $reason]);
        $invoice = $order->invoice_no;
        $order->delete();

        return redirect()->route('orders.index')->with('success', __('Order :invoice deleted', ['invoice' => $invoice]));
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
                'name' => $this->settings->shopName($order->business_id),
                'mobile' => $this->settings->shopContact($order->business_id, 'mobile'),
                'address' => $this->settings->shopContact($order->business_id, 'address'),
            ],
            'template' => (string) $this->settings->bizGet($order->business_id, "invoice.{$order->business_id}.template", 'invoice.template', 'a4'),
        ]);
    }
}
