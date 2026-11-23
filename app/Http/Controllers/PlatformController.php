<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Package;
use App\Models\SellerPayment;
use App\Models\SellerSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\DhakaTime;

/**
 * Platform (Super Admin) — the sellers are the shops that buy SkaleUp's
 * service. Packages, subscriptions, payments received from sellers, and a
 * per-seller drill-down into the seller's own shop and customers.
 */
class PlatformController extends Controller
{
    public function __construct()
    {
        abort_unless((bool) (auth()->user()?->is_platform_admin), 403);
    }

    /** Point Packs live inside Settings (Seller Packages group); this route is the platform menu's way in. The constructor guard above makes it platform-only, like every other /admin page. */
    public function packages(): RedirectResponse
    {
        return redirect('/settings?group=packages');
    }

    /** The platform aggregates live in PlatformHomeService (ধাপ ৩) so /admin and the /dashboard platform face share one definition. */
    private function sellerRow(Business $b): array
    {
        return app(\App\Services\PlatformHomeService::class)->sellerRow($b);
    }

    public function dashboard(): Response
    {
        return Inertia::render('Platform/Dashboard', app(\App\Services\PlatformHomeService::class)->dashboardData());
    }

    public function sellers(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $sellers = Business::orderBy('name')
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('owner_name', 'like', "%{$q}%")))
            ->get()->map(fn ($b) => $this->sellerRow($b));

        return Inertia::render('Platform/Sellers', [
            'sellers' => $sellers->all(),
            'q' => $q,
            'pendingApprovals' => Business::where('status', 'pending')->count(),
        ]);
    }

    /** Approve a pending (or rejected) seller shop: full access opens. */
    public function approveSeller(Request $request, Business $business): RedirectResponse
    {
        $business->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'status_note' => null,
        ]);

        return back()->with('success', 'Seller approved. Their full dashboard is now open.');
    }

    /** Reject a seller shop; the note is shown to the seller. */
    public function rejectSeller(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'status_note' => ['required', 'string', 'max:500'],
        ]);
        $business->update([
            'status' => 'rejected',
            'status_note' => $data['status_note'],
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return back()->with('success', 'Seller rejected. They will see your note on their status page.');
    }

    public function sellerShow(Business $business): Response
    {
        $row = $this->sellerRow($business);
        $customers = Customer::where('business_id', $business->id)
            // Lifetime value computed live (sum of DELIVERED order totals);
            // the stored column of that name is never written.
            ->withSum(['orders as lifetime_value_live' => fn ($x) => $x->where('status', 'Delivered')], 'grand_total')
            ->orderByDesc('lifetime_value_live')->limit(50)->get()
            ->map(function ($c) use ($business) {
                $orders = Order::where('business_id', $business->id)->where('customer_id', $c->id);
                $total = (clone $orders)->count();
                $delivered = (clone $orders)->where('status', 'Delivered')->count();

                return [
                    'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'address' => $c->address,
                    'orders' => $total, 'delivered' => $delivered,
                    'spent' => (float) (clone $orders)->whereNotIn('status', ['Cancelled'])->sum('grand_total'),
                    'last_order' => DhakaTime::fmt((clone $orders)->latest('id')->first()?->created_at, 'd M Y'),
                ];
            })->all();

        return Inertia::render('Platform/SellerDetail', [
            'seller' => $row,
            // M19: the "Package history" is the points purchase history —
            // pack purchases and the one-time onboarding fee (plus their
            // refunds), read from the SellerPayment money records.
            'purchaseHistory' => SellerPayment::where('business_id', $business->id)
                ->where(fn ($q) => $q->where('note', 'like', '%pack purchase%')
                    ->orWhere('note', 'like', 'Onboarding fee%')
                    ->orWhere('note', 'like', 'Refund — %'))
                ->latest('paid_at')->latest('id')->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'label' => $this->purchaseLabel($p),
                    'amount' => (float) $p->amount,
                    'method' => $p->method,
                    'when' => $p->paid_at?->format('d M Y, h:i A'),
                ])->all(),
            'payments' => SellerPayment::where('business_id', $business->id)->latest('paid_at')->get()
                ->map(fn ($p) => ['id' => $p->id, 'amount' => (float) $p->amount, 'method' => $p->method, 'note' => $p->note, 'when' => $p->paid_at?->format('d M Y, h:i A')])->all(),
            'packages' => \App\Models\PointPack::where('is_active', true)->orderBy('sort')->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price_bdt, 'credits' => (int) $p->credits, 'validity_type' => $p->validity_type])->all(),
            'wallet' => (function () use ($business) {
                $w = \App\Models\BusinessWallet::where('business_id', $business->id)->first();
                if (! $w) {
                    return null;
                }

                return [
                    'balance' => (int) $w->balance, 'pack' => $w->packName(),
                    'cycle_end' => $w->cycle_end?->format('d M Y'), 'wallet_expires_at' => $w->wallet_expires_at?->format('d M Y'),
                    'first_purchase_at' => $w->first_purchase_at?->format('d M Y'),
                    'refunded_at' => $w->refunded_at?->format('d M Y'),
                    'refund_eligible' => app(\App\Services\TokenService::class)->refundEligible($business->id),
                ];
            })(),
            'tokenLedger' => \App\Models\TokenLedger::where('business_id', $business->id)->latest('id')->limit(15)->get()
                ->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind, 'amount' => (int) $r->amount, 'balance_after' => (int) $r->balance_after,
                    'note' => $r->note, 'when' => $r->created_at?->format('d M Y, h:i A')])->all(),
            'claims' => \App\Models\SellerPaymentClaim::where('business_id', $business->id)->latest('id')->limit(10)->get()
                ->map(fn ($c) => $this->claimRow($c))->all(),
            'customers' => $customers,
        ]);
    }

    /** Display label for one purchase-history money row. */
    private function purchaseLabel(SellerPayment $p): string
    {
        $note = trim((string) $p->note);
        if (preg_match('/^(.+?)\s+pack purchase$/', $note, $m)) {
            return $m[1].' pack';
        }

        return $note !== '' ? $note : 'Pack purchase';
    }

    /** Record a point-pack purchase for a seller (M19 v1: paid offline). */
    public function subscribe(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'package_id' => ['required', 'exists:point_packs,id'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'method' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        try {
            app(\App\Services\TokenService::class)->purchase(
                $business->id, (int) $data['package_id'], $data['amount'] ?? null,
                $data['method'], $data['note'] ?? null, $request->user()?->id,
            );
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Purchase recorded - points credited');
    }

    /** The 7-day refund of a seller's first purchase (M19, once only). */
    public function refundFirstPurchase(Request $request, Business $business): RedirectResponse
    {
        try {
            app(\App\Services\TokenService::class)->refundFirstPurchase($business->id, $request->user()?->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Refund done - points removed');
    }

    // ---------- M19 purchase flow: seller payment claims ----------

    private function claimRow(\App\Models\SellerPaymentClaim $c): array
    {
        return [
            'id' => $c->id,
            'business_id' => (int) $c->business_id,
            'shop' => $c->business?->name,
            'pack_name' => $c->pack_name,
            'pack_credits' => (int) $c->pack_credits,
            'validity_type' => $c->validity_type,
            'amount_bdt' => (float) $c->amount_bdt,
            'onboarding_fee_bdt' => (float) $c->onboarding_fee_bdt,
            'total_bdt' => (float) $c->total_bdt,
            'method' => $c->method,
            'sender_number' => $c->sender_number,
            'trx_id' => $c->trx_id,
            'status' => $c->status,
            'admin_note' => $c->admin_note,
            // The claim records what was CREDITED; the wallet may already
            // have spent some of it — show the live balance alongside so
            // "330 credited" and a "329" balance never look contradictory.
            'current_balance' => (int) (\App\Models\BusinessWallet::where('business_id', $c->business_id)->value('balance') ?? 0),
            'seller_pending' => $c->business?->status === 'pending',
            'when' => DhakaTime::fmt($c->created_at, 'd M Y, h:i A'),
            'decided_at' => DhakaTime::fmt($c->decided_at, 'd M Y, h:i A'),
        ];
    }

    /**
     * Platform Audit Log — the view-as-seller trail (platform_view_logs,
     * ধাপ ৩). The table stores one row per event (enter / exit /
     * expired / write_blocked); this page pairs each enter with its
     * closing event per admin + shop so the admin reads SESSIONS:
     * who viewed which shop, when they went in, when they came out
     * (or that the 30-minute view auto-expired), and how long it
     * lasted. Read-only; platform context only (constructor guard).
     */
    public function auditLog(Request $request): Response
    {
        // Newest 1000 events are more than any real pairing window;
        // pairing walks them oldest first.
        $events = \App\Models\PlatformViewLog::with(['admin:id,name,email', 'business:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(1000)->get();

        $sessions = [];
        $open = [];
        $close = function (array $s, $exitedAt, string $state) use (&$sessions) {
            $s['exited_at'] = $exitedAt;
            $s['state'] = $state;
            $sessions[] = $s;
        };
        foreach ($events->reverse() as $e) {
            $key = $e->admin_id . ':' . $e->business_id;
            if ($e->action === 'enter') {
                if (isset($open[$key])) {
                    // A second enter with no closing event in between:
                    // the earlier view was superseded (its TTL had run
                    // out, or the admin re-entered from another tab).
                    $close($open[$key], $e->created_at, 'replaced');
                    unset($open[$key]);
                }
                $open[$key] = [
                    'admin_name' => $e->admin?->name ?? 'Unknown admin',
                    'admin_email' => $e->admin?->email ?? '',
                    'shop' => $e->detail['shop_name'] ?? $e->business?->name ?? 'Unknown shop',
                    'entered_at' => $e->created_at,
                    'exited_at' => null,
                    'state' => 'open',
                    'blocked' => 0,
                ];
            } elseif ($e->action === 'exit' || $e->action === 'expired') {
                $state = $e->action === 'expired' ? 'expired'
                    : ((($e->detail['reason'] ?? null) === 'replaced') ? 'replaced' : 'closed');
                if (isset($open[$key])) {
                    $close($open[$key], $e->created_at, $state);
                    unset($open[$key]);
                } else {
                    // A closing event whose enter fell outside the
                    // fetched window: still show the event itself.
                    $sessions[] = [
                        'admin_name' => $e->admin?->name ?? 'Unknown admin',
                        'admin_email' => $e->admin?->email ?? '',
                        'shop' => $e->detail['shop_name'] ?? $e->business?->name ?? 'Unknown shop',
                        'entered_at' => null,
                        'exited_at' => $e->created_at,
                        'state' => $state,
                        'blocked' => 0,
                    ];
                }
            } elseif ($e->action === 'write_blocked' && isset($open[$key])) {
                $open[$key]['blocked']++;
            }
        }
        $now = now();
        foreach ($open as $s) {
            // No closing event: still inside the 30-minute window, or
            // the window ran out and no later request recorded the
            // expiry - either way the view is over at entry + TTL.
            $s['state'] = $s['entered_at']->diffInSeconds($now) >= \App\Support\ShopContext::TTL ? 'expired' : 'open';
            $sessions[] = $s;
        }

        // Newest session first (by entry, falling back to exit).
        usort($sessions, fn ($a, $b) => ($b['entered_at'] ?? $b['exited_at']) <=> ($a['entered_at'] ?? $a['exited_at']));

        $total = count($sessions);
        $perPage = 25;
        $page = max(1, (int) $request->query('page', 1));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $rows = [];
        foreach (array_slice($sessions, ($page - 1) * $perPage, $perPage) as $s) {
            $end = $s['exited_at'] ?? ($s['state'] === 'expired' && $s['entered_at'] ? $s['entered_at']->copy()->addSeconds(\App\Support\ShopContext::TTL) : $now);
            // Carbon 3 diffs are signed (from $this towards the
            // argument), so the diff runs from entry to end.
            $seconds = $s['entered_at'] ? (int) max(0, $s['entered_at']->diffInSeconds($end)) : null;
            $rows[] = [
                'admin_name' => $s['admin_name'],
                'admin_email' => $s['admin_email'],
                'shop' => $s['shop'],
                'entered_at' => DhakaTime::fmt($s['entered_at'], 'd M Y, h:i A'),
                'exited_at' => DhakaTime::fmt($s['exited_at'], 'd M Y, h:i A'),
                'state' => $s['state'],
                'duration' => $seconds === null ? null : self::auditDuration($seconds),
                'blocked' => $s['blocked'],
            ];
        }

        return Inertia::render('Platform/AuditLog', [
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'last_page' => $lastPage,
                'total' => $total,
                'from' => $total === 0 ? 0 : ($page - 1) * $perPage + 1,
                'to' => min($total, $page * $perPage),
            ],
        ]);
    }

    /** Human duration for an audit session: "38 sec", "12 min", "1 h 5 min". */
    private static function auditDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' sec';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return $minutes . ' min';
        }

        return intdiv($minutes, 60) . ' h ' . ($minutes % 60) . ' min';
    }

    /** Payment claims inbox — submitted payments wait here for a decision. */
    public function claims(): Response
    {
        $claims = \App\Models\SellerPaymentClaim::with('business')
            ->orderByRaw("CASE WHEN status = 'pending_review' THEN 0 WHEN status = 'pending_payment' THEN 1 ELSE 2 END")
            ->orderByDesc('id')->get();

        return Inertia::render('Platform/Claims', [
            'claims' => $claims->map(fn ($c) => $this->claimRow($c))->all(),
            'counts' => [
                'pending_review' => $claims->where('status', 'pending_review')->count(),
                'pending_payment' => $claims->where('status', 'pending_payment')->count(),
                'approved' => $claims->where('status', 'approved')->count(),
                'rejected' => $claims->where('status', 'rejected')->count(),
            ],
        ]);
    }

    /**
     * Approve a submitted claim — credits the pack's points (once
     * only). When the seller's business is still pending, this one
     * action also activates the account (see PaymentClaimService).
     */
    public function approveClaim(Request $request, \App\Models\SellerPaymentClaim $claim): RedirectResponse
    {
        $activatesSeller = $claim->business?->status === 'pending';
        try {
            app(\App\Services\PaymentClaimService::class)->approve($claim, $request->user()?->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $activatesSeller
            ? 'Payment approved — points credited and the seller is now active'
            : 'Payment approved — points credited');
    }

    /** Reject a claim with a note the seller will see. */
    public function rejectClaim(Request $request, \App\Models\SellerPaymentClaim $claim): RedirectResponse
    {
        $data = $request->validate([
            'admin_note' => ['required', 'string', 'max:300'],
        ]);
        try {
            app(\App\Services\PaymentClaimService::class)->reject($claim, $request->user()?->id, $data['admin_note']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment claim rejected');
    }

    public function storePayment(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        SellerPayment::create($data + ['business_id' => $business->id, 'paid_at' => now()]);

        return back()->with('success', 'Payment recorded');
    }

    public function updateSeller(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:400'],
        ]);
        $business->update($data);

        return back()->with('success', 'Seller details saved');
    }
}
