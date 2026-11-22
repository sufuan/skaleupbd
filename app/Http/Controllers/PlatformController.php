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

    private function currentSub(int $businessId): ?SellerSubscription
    {
        return SellerSubscription::where('business_id', $businessId)->with('package')
            ->orderByDesc('id')->first();
    }

    private function sellerRow(Business $b): array
    {
        $sub = $this->currentSub($b->id);
        $orders = Order::where('business_id', $b->id);
        $live = $sub && $sub->isLive();
        $wallet = \App\Models\BusinessWallet::where('business_id', $b->id)->first();

        // M19 truth: the points wallet decides the pack display. The
        // legacy subscription only fills the gap for a shop that has a
        // subscription but never bought points (pre-M19 records).
        $packName = $sub?->package?->name;
        $status = $sub ? ($live ? 'active' : ($sub->status === 'cancelled' ? 'cancelled' : 'expired')) : 'none';
        $expiresAt = $sub?->expires_at?->format('d M Y');
        $daysLeft = $sub ? (int) today()->diffInDays($sub->expires_at, false) : null;
        if ($wallet && $wallet->first_purchase_at) {
            $until = $wallet->cycle_end ?? $wallet->wallet_expires_at;
            $packName = $wallet->packName() ?? $packName;
            $status = ($until && $until->isPast()) ? 'expired' : 'active';
            $expiresAt = $until?->format('d M Y');
            $daysLeft = $until ? (int) today()->diffInDays($until, false) : null;
        }

        return [
            'id' => $b->id,
            'name' => $b->name,
            'public_id' => $b->public_id,
            'approval_status' => $b->status ?: 'approved',
            'status_note' => $b->status_note,
            'approved_at' => $b->approved_at?->format('d M Y, h:i A'),
            'owner_name' => $b->owner_name,
            'phone' => $b->phone,
            'email' => $b->email,
            'address' => $b->address,
            'joined' => DhakaTime::fmt($b->created_at, 'd M Y'),
            'package' => $packName,
            'package_price' => $sub ? (float) $sub->amount : null,
            'status' => $status,
            'expires_at' => $expiresAt,
            'days_left' => $daysLeft,
            'paid_total' => (float) SellerPayment::where('business_id', $b->id)->sum('amount'),
            'token_balance' => $wallet ? (int) $wallet->balance : 0,
            'token_pack' => $wallet?->packName(),
            'token_until' => ($wallet?->cycle_end ?? $wallet?->wallet_expires_at)?->format('d M Y'),
            'orders' => (clone $orders)->count(),
            // "Sales" = the shop's booked order value, cancelled excluded —
            // the same definition as the seller's Order Report. Delivered-only
            // made every active seller read ৳0 until parcels completed.
            'revenue' => (float) (clone $orders)->whereNotIn('status', ['Cancelled'])->sum('grand_total'),
            'customers' => Customer::where('business_id', $b->id)->count(),
        ];
    }

    public function dashboard(): Response
    {
        $sellers = Business::orderBy('name')->get()->map(fn ($b) => $this->sellerRow($b));
        $payments = SellerPayment::with('business')->latest('paid_at')->limit(10)->get();
        $monthIncome = (float) SellerPayment::whereYear('paid_at', now()->year)->whereMonth('paid_at', now()->month)->sum('amount');

        return Inertia::render('Platform/Dashboard', [
            'stats' => [
                'sellers' => $sellers->count(),
                'active' => $sellers->where('status', 'active')->count(),
                'expired' => $sellers->where('status', 'expired')->count(),
                'income_total' => (float) SellerPayment::sum('amount'),
                'income_month' => $monthIncome,
                'expiring_soon' => $sellers->filter(fn ($s) => $s['status'] === 'active' && $s['days_left'] !== null && $s['days_left'] <= 7)->count(),
            ],
            'sellers' => $sellers->all(),
            'recentPayments' => $payments->map(fn ($p) => [
                'id' => $p->id, 'seller' => $p->business?->name, 'amount' => (float) $p->amount,
                'method' => $p->method, 'note' => $p->note, 'when' => $p->paid_at?->format('d M Y, h:i A'),
            ])->all(),
            'topSellers' => $sellers->sortByDesc('revenue')->take(5)->values()->all(),
            'expiring' => $sellers->filter(fn ($s) => $s['status'] === 'active' && $s['days_left'] !== null && $s['days_left'] <= 7)->values()->all(),
            'packages' => \App\Models\PointPack::where('is_active', true)->orderBy('sort')->get()->map(fn ($p) => [
                'name' => $p->name, 'price' => (float) $p->price_bdt,
                'sellers' => \App\Models\BusinessWallet::where('pack_id', $p->id)->count(),
            ])->all(),
        ]);
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
        $customers = Customer::where('business_id', $business->id)->orderByDesc('lifetime_value')->limit(50)->get()
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
