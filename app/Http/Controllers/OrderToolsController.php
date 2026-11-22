<?php

namespace App\Http\Controllers;

use App\Models\CallLog;
use App\Models\IpPhoneBlock;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Services\OrderService;
use App\Services\PhoneNormalizer;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\DhakaTime;

class OrderToolsController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly SettingsService $settings,
    ) {}

    private function biz(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    // ---------- Auto Pick ----------
    public function autopick(Request $request): Response
    {
        $biz = $this->biz($request);
        $queues = [];
        foreach (Order::PROCESSING_STATUSES as $s) {
            $q = Order::where('business_id', $biz)->whereIn('status', ['Pending', 'Incomplete']);
            $count = $s === 'Processing'
                ? $q->where(fn ($x) => $x->whereNull('processing_status')->orWhere('processing_status', 'Processing'))->count()
                : $q->where('processing_status', $s)->count();
            $queues[] = ['status' => $s, 'count' => $count];
        }
        $today = OrderActivity::whereHas('order', fn ($q) => $q->where('business_id', $biz))
            ->where('kind', 'action')->where('text', 'like', 'Approved by%')->whereDate('created_at', today())
            ->where('actor_name', $request->user()->name)->count();

        return Inertia::render('Orders/AutoPick', [
            'queues' => $queues,
            'autoOpen' => (bool) $this->settings->get('orders.autopick_auto_open', true),
            'myToday' => $today,
        ]);
    }

    public function autopickNext(Request $request, string $status): RedirectResponse
    {
        $biz = $this->biz($request);
        $q = Order::where('business_id', $biz)->whereIn('status', ['Pending', 'Incomplete'])->oldest();
        if ($status === 'Processing') {
            $q->where(fn ($x) => $x->whereNull('processing_status')->orWhere('processing_status', 'Processing'));
        } else {
            $q->where('processing_status', $status);
        }
        $order = $q->first();
        if (! $order) {
            return back()->with('success', 'That queue is empty');
        }

        return redirect()->route('orders.show', $order);
    }

    public function autopickToggle(Request $request): RedirectResponse
    {
        $this->settings->set('orders.autopick_auto_open', $request->boolean('enabled'), $request->user()->id);

        return back();
    }

    // ---------- Auto Call Center ----------
    public function autocall(Request $request): Response
    {
        $biz = $this->biz($request);
        $today = CallLog::where('business_id', $biz)->whereDate('created_at', today());

        return Inertia::render('Orders/AutoCall', [
            'stats' => [
                'calls_today' => (clone $today)->count(),
                'verified' => (clone $today)->where('outcome', 'verified')->count(),
                'failed' => (clone $today)->whereIn('outcome', ['failed', 'no_answer', 'phone_off', 'wrong_number'])->count(),
                'minutes' => (int) (clone $today)->sum('minutes'),
                'waiting' => Order::where('business_id', $biz)->where('status', 'Pending')->count(),
                'balance' => (float) $this->settings->get('autocall.balance', 1250),
            ],
            'waitingOrders' => Order::where('business_id', $biz)->where('status', 'Pending')->oldest()
                ->get()->map(fn ($o) => ['id' => $o->id, 'invoice' => $o->invoice_no, 'name' => $o->customer_name, 'phone' => $o->customer_phone, 'total' => (float) $o->grand_total]),
            'logs' => CallLog::where('business_id', $biz)->latest('created_at')->limit(20)->get()
                ->map(fn ($l) => ['phone' => $l->phone, 'staff' => $l->staff_name, 'outcome' => $l->outcome, 'minutes' => $l->minutes, 'time' => DhakaTime::fmt($l->created_at, 'h:i A')]),
        ]);
    }

    public function autocallLog(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order_id' => ['nullable', 'integer'],
            'phone' => ['required', 'string', 'max:25'],
            'outcome' => ['required', 'in:verified,no_answer,phone_off,wrong_number,failed'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:60'],
        ]);
        $biz = $this->biz($request);
        CallLog::create(['business_id' => $biz, 'order_id' => $data['order_id'] ?? null,
            'phone' => PhoneNormalizer::normalize($data['phone']), 'staff_name' => $request->user()->name,
            'outcome' => $data['outcome'], 'minutes' => $data['minutes'] ?? 1]);
        if (! empty($data['order_id'])) {
            $order = Order::where('business_id', $biz)->find($data['order_id']);
            if ($order) {
                $order->auto_call_status = $data['outcome'] === 'verified' ? 'verified' : 'failed';
                $order->save();
                $order->logActivity('action', "Call logged by {$request->user()->name} — outcome: {$data['outcome']}", $request->user());
            }
        }
        $balance = (float) $this->settings->get('autocall.balance', 1250);
        $this->settings->set('autocall.balance', max(0, $balance - (float) ($data['minutes'] ?? 1) * 1.0), $request->user()->id);

        return back()->with('success', 'Call outcome logged');
    }

    // ---------- Block List ----------
    public function blocklist(Request $request): Response
    {
        $biz = $this->biz($request);
        $blocks = IpPhoneBlock::where('business_id', $biz)->latest()->get()
            ->map(fn ($b) => ['id' => $b->id, 'type' => $b->type, 'value' => $b->value, 'reason' => $b->reason,
                'added_by' => $b->added_by, 'blocked_at' => DhakaTime::fmt($b->created_at, 'd M Y, h:i A'),
                'expires' => $b->expires_at?->format('d M Y'), 'expired' => $b->expires_at && $b->expires_at->isPast()]);

        return Inertia::render('Orders/BlockList', ['blocks' => $blocks]);
    }

    public function blockStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:ip,phone,device'],
            'value' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:160'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
        $value = $data['type'] === 'phone' ? PhoneNormalizer::normalize($data['value']) : $data['value'];
        IpPhoneBlock::firstOrCreate(
            ['business_id' => $this->biz($request), 'type' => $data['type'], 'value' => $value],
            ['reason' => $data['reason'] ?? null, 'added_by' => $request->user()->name,
                'expires_at' => ! empty($data['expires_days']) ? now()->addDays($data['expires_days']) : null]
        );

        return back()->with('success', 'Blocked');
    }

    public function blockDestroy(Request $request, IpPhoneBlock $block): RedirectResponse
    {
        abort_unless($block->business_id === $this->biz($request), 404);
        if ($block->type === 'phone') {
            \App\Models\Customer::where('business_id', $block->business_id)->where('phone', $block->value)
                ->update(['is_blacklisted' => false]);
        }
        $block->delete();

        return back()->with('success', 'Unblocked');
    }

    public function blockCleanup(Request $request): RedirectResponse
    {
        $n = IpPhoneBlock::where('business_id', $this->biz($request))
            ->whereNotNull('expires_at')->where('expires_at', '<', now())->delete();

        return back()->with('success', "{$n} expired entries removed");
    }

    // ---------- Preorders ----------
    public function preorders(Request $request): Response
    {
        $biz = $this->biz($request);
        $tab = $request->query('tab', 'all');
        $reason = (string) $request->query('reason', '');
        $code = trim((string) $request->query('code', ''));
        $q = Order::where('business_id', $biz)->where('is_preorder', true)->with('items')->latest();
        if ($tab === 'notified') {
            $q->where('preorder_notified', true);
        } elseif ($tab === 'pending') {
            $q->where('preorder_notified', false);
        }
        if ($reason !== '') {
            $q->where('preorder_reason', $reason);
        }
        if ($code !== '') {
            $q->whereHas('items', fn ($x) => $x->where('sku', 'like', "%{$code}%")->orWhere('name', 'like', "%{$code}%"));
        }
        $counts = [
            'all' => Order::where('business_id', $biz)->where('is_preorder', true)->count(),
            'notified' => Order::where('business_id', $biz)->where('is_preorder', true)->where('preorder_notified', true)->count(),
            'pending' => Order::where('business_id', $biz)->where('is_preorder', true)->where('preorder_notified', false)->count(),
        ];

        return Inertia::render('Orders/Preorders', [
            'orders' => $q->get()->map(fn ($o) => ['id' => $o->id, 'invoice' => $o->invoice_no, 'name' => $o->customer_name,
                'phone' => $o->customer_phone, 'items_text' => $o->items->map(fn ($i) => $i->name)->implode(', '),
                'reason' => $o->preorder_reason, 'notify_date' => $o->notify_date?->format('d M Y'),
                'notified' => (bool) $o->preorder_notified, 'status' => $o->status, 'total' => (float) $o->grand_total]),
            'counts' => $counts, 'tab' => $tab,
            'reasons' => Order::where('business_id', $biz)->where('is_preorder', true)
                ->whereNotNull('preorder_reason')->distinct()->pluck('preorder_reason')->filter()->values(),
            'filters' => ['reason' => $reason, 'code' => $code],
        ]);
    }

    public function preorderNotify(Request $request, Order $order): RedirectResponse
    {
        abort_unless($order->business_id === $this->biz($request), 404);
        $order->preorder_notified = true;
        $order->save();
        $order->logActivity('sms', "Preorder notification sent to customer by {$request->user()->name}", $request->user());

        return back()->with('success', 'Customer notified');
    }

    // ---------- Orders Dashboard (report) ----------
    public function dashboard(Request $request): Response
    {
        $biz = $this->biz($request);
        $f = $request->only(['source', 'status', 'from', 'to', 'search', 'district', 'taken_by',
            'product', 'courier', 'payment_status', 'processing_status', 'tag']);
        $hasFilter = collect($f)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
        $result = null;
        if ($hasFilter) {
            $q = Order::where('business_id', $biz);
            $this->applyDashboardFilters($q, $f);
            $orders = $q->get();
            $result = [
                'total' => $orders->count(),
                'revenue' => (float) $orders->whereNotIn('status', ['Cancelled'])->sum('grand_total'),
                'breakdown' => $orders->groupBy('status')->map(fn ($g) => $g->count()),
            ];
        }

        return Inertia::render('Orders/Dashboard', [
            'filters' => $f, 'hasFilter' => $hasFilter, 'result' => $result,
            'sources' => \App\Models\OrderSource::where('business_id', $biz)->orderBy('sort')->get(['code', 'label']),
            'statuses' => array_merge(Order::STATUSES, Order::EXTRA_STATUSES),
            'couriers' => app(\App\Services\DeliveryMethodList::class)->forBusiness($biz),
            'processingStatuses' => Order::PROCESSING_STATUSES,
        ]);
    }

    private function applyDashboardFilters($q, array $f): void
    {
        if (! empty($f['source'])) $q->where('source', $f['source']);
        if (! empty($f['status'])) $q->where('status', $f['status']);
        if (! empty($f['from'])) $q->whereDate('created_at', '>=', $f['from']);
        if (! empty($f['to'])) $q->whereDate('created_at', '<=', $f['to']);
        if (! empty($f['search'])) $q->where('invoice_no', 'like', '%'.$f['search'].'%');
        if (! empty($f['district'])) $q->where('district', $f['district']);
        if (! empty($f['taken_by'])) $q->where('taken_by', $f['taken_by']);
        if (! empty($f['product'])) $q->whereHas('items', fn ($x) => $x->where('name', 'like', '%'.$f['product'].'%')->orWhere('sku', 'like', '%'.$f['product'].'%'));
        if (! empty($f['courier'])) $q->where('delivery_method', 'like', $f['courier'].'%');
        if (! empty($f['processing_status'])) $q->where('processing_status', $f['processing_status']);
        if (! empty($f['tag'])) $q->where('tags', 'like', '%"'.$f['tag'].'"%');
        if (($f['payment_status'] ?? '') === 'full') $q->whereRaw('advance >= grand_total')->where('grand_total', '>', 0);
        if (($f['payment_status'] ?? '') === 'partial') $q->whereRaw('advance > 0')->whereRaw('advance < grand_total');
        if (($f['payment_status'] ?? '') === 'none') $q->whereRaw('advance <= 0');
    }

    public function dashboardExport(Request $request)
    {
        $biz = $this->biz($request);
        $f = $request->only(['source', 'status', 'from', 'to', 'search', 'district', 'taken_by',
            'product', 'courier', 'payment_status', 'processing_status', 'tag']);
        $q = Order::where('business_id', $biz)->with(['items', 'processor'])->latest();
        $this->applyDashboardFilters($q, $f);
        $orders = $q->limit(5000)->get();

        return response()->streamDownload(function () use ($orders) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Invoice', 'Date', 'Customer', 'Phone', 'District', 'Source', 'Status', 'Delivery Method', 'Items', 'Advance', 'Due', 'Grand Total', 'Taken By', 'Processed By']);
            foreach ($orders as $o) {
                fputcsv($out, [$o->invoice_no, $o->created_at?->format('d M Y h:i A'), $o->customer_name, $o->customer_phone,
                    $o->district, $o->source, $o->status, $o->delivery_method,
                    $o->items->map(fn ($i) => $i->name.($i->qty > 1 ? " x{$i->qty}" : ''))->implode(', '),
                    $o->advance, $o->due, $o->grand_total, $o->taken_by, $o->processor?->name]);
            }
            fclose($out);
        }, 'orders-report.csv', ['Content-Type' => 'text/csv']);
    }

    // ---------- Super Edit ----------
    public function superEdit(Request $request): Response
    {
        abort_unless($request->user()->is_admin, 403);
        $biz = $this->biz($request);
        $order = null;
        if ($invoice = $request->query('invoice')) {
            $found = Order::where('business_id', $biz)->where('invoice_no', strtoupper(trim($invoice)))->with('items')->first();
            if ($found) {
                $order = ['id' => $found->id, 'invoice' => $found->invoice_no, 'name' => $found->customer_name,
                    'phone' => $found->customer_phone, 'status' => $found->status, 'total' => (float) $found->grand_total,
                    'items_text' => $found->items->map(fn ($i) => $i->name)->implode(', ')];
            }
        }
        $recent = OrderActivity::where('kind', 'super_edit')
            ->whereHas('order', fn ($q) => $q->where('business_id', $biz))
            ->latest('created_at')->limit(15)->get()
            ->map(fn ($a) => ['text' => $a->text, 'time' => DhakaTime::fmt($a->created_at, 'd M, h:i A'), 'by' => $a->actor_name]);

        return Inertia::render('Orders/SuperEdit', [
            'order' => $order, 'searched' => $request->query('invoice'),
            'statuses' => array_merge(Order::STATUSES, Order::EXTRA_STATUSES), 'recent' => $recent,
        ]);
    }

    public function superEditSave(Request $request, Order $order): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        abort_unless($order->business_id === $this->biz($request), 404);
        $to = $request->validate(['status' => ['required', 'string']])['status'];
        $from = $order->status;
        $this->orders->changeStatus($order, $to, $request->user(), 'Super Edit override');
        $order->logActivity('super_edit', "Super Edit by {$request->user()->name}: status {$from} → {$to} on {$order->invoice_no}", $request->user(),
            ['before' => $from, 'after' => $to]);

        return redirect()->route('orders.superedit', ['invoice' => $order->invoice_no])->with('success', 'Override saved');
    }
}
