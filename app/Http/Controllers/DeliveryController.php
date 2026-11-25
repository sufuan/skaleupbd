<?php

namespace App\Http\Controllers;

use App\Models\CourierBookingLog;
use App\Models\CourierMethod;
use App\Models\Order;
use App\Models\ScanEvent;
use App\Services\BookingService;
use App\Services\Couriers\DriverFactory;
use App\Services\Couriers\ManualDriver;
use App\Services\Couriers\MockDriver;
use App\Support\DhakaTime;
use App\Services\OrderService;
use App\Services\SuggestCourierService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeliveryController extends Controller
{
    public function __construct(private BookingService $booking, private SuggestCourierService $suggester) {}

    private function biz(Request $request): int
    {
        return \App\Support\ShopContext::id($request);
    }

    private function methods(int $biz)
    {
        return CourierMethod::where('business_id', $biz)->where('is_active', true)->with('pickupStores')->orderBy('name')->get();
    }

    /**
     * True when the order's courier has no status feed of its own, so a
     * human scan is the only way its delivery can ever be recorded: no
     * courier at all (hand delivery), a manual courier (ManualDriver),
     * or an API courier running on the mock driver in test mode. A real
     * API courier reports delivery itself (webhook / status pull) and
     * must never be completed by hand.
     */
    private function deliverableByHand(Order $order): bool
    {
        $method = $order->courierMethod;
        if (! $method) {
            return true;
        }
        $driver = DriverFactory::for($method);

        return $driver instanceof ManualDriver || $driver instanceof MockDriver;
    }

    private function methodOptions(int $biz, ?Order $order = null): array
    {
        return $this->methods($biz)->map(function (CourierMethod $m) use ($order) {
            return [
                'id' => $m->id, 'name' => $m->name, 'label' => $m->label(), 'type' => $m->courier_type,
                'class' => $m->courierClass(), 'booking_mode' => $m->bookingMode(),
                'charge' => $order ? $m->chargeFor($order->effectiveDistrict()) : null,
                'charge_inside' => (float) $m->charge_inside_dhaka, 'charge_outside' => (float) $m->charge_outside_dhaka,
                'is_default' => $m->is_default, 'is_preferred' => $m->is_preferred,
                // A store id saved on the connection itself (credentials)
                // is a working pickup store — the modal must not warn
                // "no store synced" when one is saved there.
                'has_store_id' => ! empty($m->credentials['store_id']),
                'stores' => $m->pickupStores->map(fn ($s) => ['id' => $s->id, 'store_id' => $s->store_id, 'name' => $s->name, 'is_default' => $s->is_default])->all(),
            ];
        })->all();
    }

    public function ready(Request $request)
    {
        $biz = $this->biz($request);
        $search = trim((string) $request->query('search', ''));
        $orders = Order::with(['items', 'customer'])
            ->where('business_id', $biz)->where('status', 'Processing')
            ->when($search, function ($q) use ($search) {
                $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$search}%")->orWhere('customer_phone', 'like', '%' . preg_replace('/\D/', '', $search) . '%'));
            })
            ->orderBy('approved_at')->get();

        $activeMethods = $this->methods($biz);
        $rows = $orders->map(function (Order $o) use ($activeMethods) {
            // suggest() returns null when the shop has no courier method
            // at all — normalise so the row fields below can use ?? safely.
            $suggestion = $this->suggester->suggest($o, $activeMethods) ?? ['method' => null, 'reason' => null];

            $district = $o->effectiveDistrict();
            // S-F8: no em-dash in UI text (owner rule) - a plain hyphen
            // marks "no approval stamp to measure from".
            $waiting = '-';
            if ($o->approved_at) {
                $mins = (int) $o->approved_at->diffInMinutes(now());
                $waiting = $mins >= 1440
                    ? intdiv($mins, 1440) . 'd ' . intdiv($mins % 1440, 60) . 'h'
                    : ($mins >= 60 ? intdiv($mins, 60) . 'h ' . str_pad((string) ($mins % 60), 2, '0', STR_PAD_LEFT) . 'm' : $mins . 'm');
            }

            return [
                'id' => $o->id, 'invoice' => $o->invoice, 'customer_name' => $o->customer_name,
                'customer_phone' => $o->customer_phone, 'district' => $district, 'area' => $o->area,
                'date' => DhakaTime::fmt($o->created_at, 'j M, g:i a'),
                'zone' => CourierMethod::isInsideDhaka($district) ? 'Inside Dhaka' : 'Outside Dhaka',
                'items_count' => (int) $o->items->sum('qty'),
                'items_label' => $o->items->map(fn ($i) => $i->name . ' × ' . $i->qty)->implode(', '),
                'cod' => $o->codAmount(), 'status' => $o->status,
                'waiting' => $waiting,
                'booking_status' => $o->booking_status,
                'last_error' => $o->bookingLogs()->where('booking_status', 'failed')->latest()->value('error'),
                'suggested_method_id' => $suggestion['method']->id ?? null,
                'suggested_label' => $suggestion['method']?->label(),
                'suggested_reason' => $suggestion['reason'] ?? null,
            ];
        })->all();

        return Inertia::render('Delivery/ReadyToShip', [
            'orders' => $rows,
            'methods' => $this->methodOptions($biz),
            'search' => $search,
        ]);
    }

    public function send(Request $request)
    {
        $data = $request->validate([
            'order_id' => 'required|integer',
            'method_id' => 'nullable|integer',
            'cn' => 'nullable|string|max:120',
            'pickup_store_id' => 'nullable|integer',
        ]);
        $order = Order::where('business_id', $this->biz($request))->findOrFail($data['order_id']);
        $result = $this->booking->send($order, $data['method_id'] ?? null, $data['cn'] ?? null, $data['pickup_store_id'] ?? null, $request->user());
        if (! $result['ok']) {
            return back()->with('error', $result['error']);
        }

        return back()->with('success', __('Sent via :method · :tracking', ['method' => $result['method']->label(), 'tracking' => $result['tracking_id'] ?? '']));
    }

    public function sendBulk(Request $request)
    {
        $data = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer',
            'method_id' => 'nullable|integer',
            'cn_map' => 'nullable|array',
        ]);
        $ok = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($data['order_ids'] as $id) {
            $order = Order::where('business_id', $this->biz($request))->where('status', 'Processing')->find($id);
            if (! $order) {
                // S-F7: ids that are not Processing (already shipped,
                // another shop's, deleted) were silently dropped and the
                // flash still claimed success - "Sent 0 order(s)" for a
                // batch where nothing moved. Count them and say so.
                $skipped++;
                continue;
            }
            $result = $this->booking->send($order, $data['method_id'] ?? null, $data['cn_map'][$id] ?? null, null, $request->user());
            $result['ok'] ? $ok++ : $failed++;
        }

        if ($ok === 0 && $failed === 0 && $skipped > 0) {
            return back()->with('error', __('Nothing was sent - :count order(s) were skipped because they are not in Processing.', ['count' => $skipped]));
        }

        return back()->with($failed ? 'error' : 'success', "Sent {$ok} order(s)" . ($failed ? ", {$failed} failed - they stay in the queue with the reason on the row" : '') . ($skipped ? ' ' . __(':count skipped (not in Processing).', ['count' => $skipped]) : ''));
    }

    public function retry(Request $request, Order $order)
    {
        abort_unless($order->business_id === $this->biz($request), 404);
        $result = $this->booking->retry($order, $request->user());

        return back()->with($result['ok'] ? 'success' : 'error', $result['ok'] ? __('Sent via :method', ['method' => $result['method']->label()]) : $result['error']);
    }

    public function changeCourier(Request $request, Order $order)
    {
        abort_unless($order->business_id === $this->biz($request), 404);
        $data = $request->validate(['method_id' => 'required|integer']);
        $result = $this->booking->changeCourier($order, $data['method_id'], $request->user());

        return back()->with($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Courier changed to ' . $result['method']->label() : $result['error']);
    }

    public function cancelBooking(Request $request, Order $order)
    {
        abort_unless($order->business_id === $this->biz($request), 404);
        $this->booking->cancelBooking($order, $request->user());

        return back()->with('success', __('Booking cancelled'));
    }

    public function parcels(Request $request)
    {
        $biz = $this->biz($request);
        $status = (string) $request->query('status', 'In Transit'); // the prototype's default pill; 'all' = no status filter
        $base = Order::with(['items', 'courierMethod'])
            ->where('business_id', $biz)
            ->whereNotNull('courier_method_id')
            ->whereIn('status', ['Shipped', 'Delivered', 'Returned', 'Cancelled']);

        $counts = [
            'In Transit' => (clone $base)->where('status', 'Shipped')->where(fn ($q) => $q->whereNull('courier_status')->orWhereNotIn('courier_status', ['Assigned for Delivery']))->count(),
            'Assigned for Delivery' => (clone $base)->where('status', 'Shipped')->where('courier_status', 'Assigned for Delivery')->count(),
            'Cancelled' => (clone $base)->where('status', 'Cancelled')->count(),
            'Pending Entry' => (clone $base)->where('status', 'Shipped')->whereNull('tracking_id')->count(),
            'Return Pending' => (clone $base)->where('status', 'Shipped')->whereNotNull('return_tag')->count(),
            'Returned' => (clone $base)->where('status', 'Returned')->count(),
            'Damage & Lost' => (clone $base)->whereNotNull('claim_status')->count(),
        ];
        $counts['All Parcels'] = (clone $base)->count();

        $query = clone $base;
        match ($status) {
            'In Transit' => $query->where('status', 'Shipped')->where(fn ($q) => $q->whereNull('courier_status')->orWhereNotIn('courier_status', ['Assigned for Delivery'])),
            'Assigned for Delivery' => $query->where('status', 'Shipped')->where('courier_status', 'Assigned for Delivery'),
            'Cancelled' => $query->where('status', 'Cancelled'),
            'Pending Entry' => $query->where('status', 'Shipped')->whereNull('tracking_id'),
            'Return Pending' => $query->where('status', 'Shipped')->whereNotNull('return_tag'),
            'Returned' => $query->where('status', 'Returned'),
            'Damage & Lost' => $query->whereNotNull('claim_status'),
            default => null,
        };

        // Prototype filter card: search / courier / age / tags / in-charge (rider attempt has no data source yet).
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'courier' => (string) $request->query('courier', ''),
            'age_from' => $request->query('age_from'), 'age_to' => $request->query('age_to'),
            'tag' => trim((string) $request->query('tag', '')),
            'in_charge' => (string) $request->query('in_charge', ''),
        ];
        if ($filters['search'] !== '') {
            $s = $filters['search'];
            $digits = preg_replace('/\D/', '', $s);
            $query->where(fn ($q) => $q->where('invoice_no', 'like', "%{$s}%")
                ->orWhere('customer_name', 'like', "%{$s}%")
                ->when(strlen($digits) >= 3, fn ($w) => $w->orWhere('customer_phone', 'like', "%{$digits}%")));
        }
        if ($filters['courier'] !== '') {
            $query->whereHas('courierMethod', fn ($q) => $q->where('courier_type', $filters['courier']));
        }
        $parcels = $query->latest('shipped_at')->get()->map(fn (Order $o) => [
            'id' => $o->id, 'invoice' => $o->invoice, 'customer_name' => $o->customer_name, 'customer_phone' => $o->customer_phone,
            'address' => $o->address, 'tags' => $o->tags ?? [],
            'product_name' => $o->items->first()?->name, 'product_sku' => $o->items->first()?->sku,
            'courier' => $o->courierMethod ? (\App\Models\CourierMethod::LABELS[$o->courierMethod->courier_type] ?? $o->courierMethod->label()) : null,
            'courier_label' => $o->courierMethod?->label(), 'tracking_id' => $o->tracking_id, 'consignment_id' => $o->consignment_id,
            'status' => $o->status, 'courier_status' => $o->courier_status, 'cod' => $o->codAmount(),
            'call_slot' => $o->call_slot, 'call_status' => $o->call_status, 'in_charge' => $o->in_charge,
            'return_tag' => $o->return_tag, 'claim_status' => $o->claim_status, 'claim_amount' => $o->claim_amount ? (float) $o->claim_amount : null,
            'shipped_at' => DhakaTime::fmt($o->shipped_at, 'd M, h:i A'), 'district' => $o->district,
            'age_days' => $o->shipped_at ? (int) $o->shipped_at->diffInDays(now()) : 0,
            'day_tag' => $o->shipped_at ? 'Day' . min(3, (int) $o->shipped_at->diffInDays(now()) + 1) : null,
        ])
            ->when($filters['age_from'] !== null && $filters['age_from'] !== '', fn ($c) => $c->filter(fn ($p) => $p['age_days'] >= (int) $filters['age_from']))
            ->when($filters['age_to'] !== null && $filters['age_to'] !== '', fn ($c) => $c->filter(fn ($p) => $p['age_days'] <= (int) $filters['age_to']))
            ->when($filters['tag'] !== '', fn ($c) => $c->filter(fn ($p) => collect($p['tags'])->contains(fn ($tg) => str_contains(strtolower((string) $tg), strtolower($filters['tag'])))))
            ->when($filters['in_charge'] !== '', fn ($c) => $c->filter(fn ($p) => ($p['in_charge'] ?? '') === $filters['in_charge']))
            ->values()->all();

        $methods = CourierMethod::where('business_id', $biz)->get();
        $analysis = $methods->map(function (CourierMethod $m) use ($biz) {
            $delivered = Order::where('business_id', $biz)->where('courier_method_id', $m->id)->where('status', 'Delivered')->where('delivered_at', '>=', now()->subDays(30))->count();
            $returned = Order::where('business_id', $biz)->where('courier_method_id', $m->id)->where('status', 'Returned')->where('returned_at', '>=', now()->subDays(30))->count();
            $total = $delivered + $returned;

            return ['courier' => $m->label(), 'delivered' => $delivered, 'returned' => $returned, 'return_rate' => $total ? round($returned / $total * 100, 1) : 0];
        })->all();

        $entries = CourierBookingLog::with(['order', 'method'])
            ->where('business_id', $biz)->latest()->limit(100)->get()
            ->map(fn ($l) => [
                'id' => $l->id, 'invoice' => $l->order?->invoice, 'courier' => $l->method?->label(),
                'mode' => $l->mode, 'booking_status' => $l->booking_status,
                'tracking_id' => $l->tracking_id, 'error' => $l->error,
                'at' => DhakaTime::fmt($l->created_at, 'd M, h:i A'),
            ])->all();

        $couriers = $methods->pluck('courier_type')->unique()
            ->map(fn ($ty) => ['value' => $ty, 'label' => \App\Models\CourierMethod::LABELS[$ty] ?? $ty])->values()->all();
        $incharges = (clone $base)->whereNotNull('in_charge')->where('in_charge', '!=', '')->distinct()->pluck('in_charge')->values()->all();

        return Inertia::render('Delivery/Parcels', [
            'parcels' => $parcels, 'counts' => $counts, 'status' => $status,
            'analysis' => $analysis, 'entries' => $entries,
            'filters' => $filters, 'couriers' => $couriers, 'incharges' => $incharges,
        ]);
    }

    public function updateParcel(Request $request, Order $order)
    {
        abort_unless($order->business_id === $this->biz($request), 404);
        $data = $request->validate([
            'call_slot' => 'nullable|string|max:20',
            'call_status' => 'nullable|string|max:30',
            'in_charge' => 'nullable|string|max:120',
            'return_tag' => 'nullable|string|max:120',
            'claim_status' => 'nullable|string|max:30',
            'claim_amount' => 'nullable|numeric|min:0',
        ]);
        $order->update($data);

        return back()->with('success', __('Parcel updated'));
    }

    public function forceCheck(Request $request, Order $order)
    {
        abort_unless($order->business_id === $this->biz($request), 404);
        $result = $this->booking->forceCheck($order, $request->user());

        return back()->with($result['ok'] ? 'success' : 'error', $result['ok'] ? 'Courier status: ' . $result['courier_status'] : $result['error']);
    }

    public function entriesExport(Request $request)
    {
        $biz = $this->biz($request);
        $logs = CourierBookingLog::with(['order', 'method'])->where('business_id', $biz)->latest()->limit(5000)->get();
        $csv = "Invoice,Courier,Mode,Booking Status,Tracking ID,Error,At\n";
        foreach ($logs as $l) {
            $csv .= implode(',', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', [
                $l->order?->invoice, $l->method?->label(), $l->mode, $l->booking_status, $l->tracking_id, $l->error, $l->created_at,
            ])) . "\n";
        }

        return response($csv)->header('Content-Type', 'text/csv')->header('Content-Disposition', 'attachment; filename="courier-entries.csv"');
    }

    public function scanPage(Request $request)
    {
        $biz = $this->biz($request);
        $events = ScanEvent::where('business_id', $biz)->whereBetween('created_at', DhakaTime::dayBoundsUtc(DhakaTime::today()))->latest('id')->limit(50)->get()
            ->map(fn ($e) => ['id' => $e->id, 'type' => $e->scan_type, 'result' => $e->result, 'code' => $e->code, 'note' => $e->note, 'at' => DhakaTime::fmt($e->created_at, 'h:i A')])->all();

        return Inertia::render('Delivery/Scan', ['todayEvents' => $events]);
    }

    /** Scan console endpoint — returns JSON for the live session counters. */
    public function scan(Request $request, OrderService $orders)
    {
        $data = $request->validate([
            'code' => 'required|string|max:120',
            'type' => 'required|in:shipping,delivery,return,rts',
            'return_tag' => 'nullable|string|max:120',
            'all_biz' => 'nullable|boolean',
        ]);
        $biz = $this->biz($request);
        $code = trim($data['code']);
        // Cross-business search exists only for platform admins (the Scan
        // page hides the toggle from everyone else, and it is re-checked
        // here — a seller sending all_biz=1 by hand gains nothing).
        $allBiz = ! empty($data['all_biz']) && (bool) ($request->user()->is_platform_admin ?? false);
        $order = Order::when(! $allBiz, fn ($q) => $q->where('business_id', $biz))
            ->where(fn ($q) => $q->where('invoice_no', $code)->orWhere('tracking_id', $code)->orWhere('consignment_id', $code))
            ->first();
        if ($order && $allBiz) {
            // Events and stock moves belong to the order's own business.
            $biz = (int) $order->business_id;
        }
        $fail = function (string $note) use ($biz, $data, $code, $request) {
            ScanEvent::create(['business_id' => $biz, 'scan_type' => $data['type'], 'result' => 'failed', 'code' => $code, 'note' => $note, 'actor_id' => $request->user()->id]);

            return response()->json(['ok' => false, 'note' => $note]);
        };
        if (! $order) {
            return $fail('No order found for this code');
        }
        $done = function (string $note) use ($biz, $data, $code, $order, $request) {
            ScanEvent::create(['business_id' => $biz, 'order_id' => $order->id, 'scan_type' => $data['type'], 'result' => 'success', 'code' => $code, 'note' => $note, 'actor_id' => $request->user()->id]);

            return response()->json(['ok' => true, 'note' => $note, 'invoice' => $order->invoice, 'status' => $order->status]);
        };

        switch ($data['type']) {
            case 'shipping':
                if ($order->status === 'Shipped') {
                    ScanEvent::create(['business_id' => $biz, 'order_id' => $order->id, 'scan_type' => 'shipping', 'result' => 'duplicate', 'code' => $code, 'note' => 'Already shipped', 'actor_id' => $request->user()->id]);

                    return response()->json(['ok' => false, 'duplicate' => true, 'note' => 'Already shipped']);
                }
                if ($order->status !== 'Processing') {
                    return $fail('Order is ' . $order->status . ' - it cannot be shipped from here');
                }
                if ($order->booking_status !== 'booked' || ! $order->tracking_id) {
                    return $fail('Not booked yet - send it to a courier first');
                }
                $order->shipped_at = now();
                $order->save();
                $orders->changeStatus($order, 'Shipped', $request->user(), 'Scanned to shipping');

                return $done('Shipped');
            case 'delivery':
                // The completion path for couriers with no status feed
                // (manual / mock): their parcels could be booked and
                // shipped but never marked Delivered anywhere - the
                // order page offers no Delivered for them and the
                // courier never reports one. API couriers keep their
                // webhook-driven lifecycle and are refused here.
                if ($order->status === 'Delivered') {
                    ScanEvent::create(['business_id' => $biz, 'order_id' => $order->id, 'scan_type' => 'delivery', 'result' => 'duplicate', 'code' => $code, 'note' => 'Already delivered', 'actor_id' => $request->user()->id]);

                    return response()->json(['ok' => false, 'duplicate' => true, 'note' => 'Already delivered']);
                }
                if ($order->status !== 'Shipped') {
                    return $fail('Order is ' . $order->status . ' - only a shipped parcel can be marked delivered');
                }
                if (! $this->deliverableByHand($order)) {
                    return $fail('This courier confirms delivery itself - it cannot be marked delivered by a scan');
                }
                $order->delivered_at = now();
                $order->courier_status = 'Delivered';
                $order->save();
                $orders->changeStatus($order, 'Delivered', $request->user(), 'Scanned as delivered');

                return $done('Delivered');
            case 'return':
                if (! in_array($order->status, ['Shipped', 'Delivered'], true)) {
                    return $fail('Only a shipped or delivered parcel can be returned');
                }
                $order->returned_at = now();
                if (! empty($data['return_tag'])) {
                    $order->return_tag = $data['return_tag'];
                }
                if (! empty($order->courier_status)) {
                    $order->courier_status = 'Returned';
                }
                $order->save();
                $orders->changeStatus($order, 'Returned', $request->user(), 'Scanned as return');

                return $done('Returned - stock is back in');
            case 'rts':
                if ($order->status !== 'Shipped') {
                    return $fail('Only a shipped parcel can come back to the store (RTS)');
                }
                $order->returned_at = now();
                if (! empty($order->courier_status)) {
                    $order->courier_status = 'Returned';
                }
                $order->save();
                $orders->changeStatus($order, 'Returned', $request->user(), 'Returned to store (RTS scan)');

                return $done('Back in the store');
        }

        return $fail('Unknown scan type');
    }

    public function packingSlip(Request $request, Order $order, \App\Services\SettingsService $settings)
    {
        abort_unless($order->business_id === $this->biz($request), 404);
        $order->load(['items', 'courierMethod']);

        return Inertia::render('Orders/PackingSlip', [
            'order' => [
                'invoice' => $order->invoice, 'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone, 'address' => $order->address,
                'area' => $order->area, 'district' => $order->district,
                'cod' => $order->codAmount(),
                'courier' => $order->courierMethod?->label() ?? $order->delivery_method,
                'tracking_id' => $order->tracking_id, 'consignment_id' => $order->consignment_id,
                // Y3-F3: the packing slip is the one document that must
                // carry the variant - packers pick from it.
                'items' => $order->items->map(fn ($i) => ['name' => $i->variant_name ? "{$i->name} ({$i->variant_name})" : $i->name, 'qty' => $i->qty])->all(),
                'delivery_note' => $order->delivery_note,
            ],
            'shop' => [
                'name' => $settings->shopName($order->business_id),
                'mobile' => $settings->shopContact($order->business_id, 'mobile'),
                'address' => $settings->shopContact($order->business_id, 'address'),
            ],
        ]);
    }
}
