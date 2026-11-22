<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PointPack;
use App\Models\SellerPaymentClaim;
use App\Models\TokenLedger;
use App\Services\PaymentClaimService;
use App\Services\TokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\DhakaTime;

/**
 * M19 — the seller's Points & Billing page: balance, active pack,
 * usage ledger, how to pay, and the self-serve purchase flow (pick a
 * pack → pay offline → submit the payment → admin confirms → points).
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly PaymentClaimService $claims,
    ) {}

    private function claimView(SellerPaymentClaim $c): array
    {
        return [
            'id' => $c->id,
            'pack_id' => (int) $c->pack_id,
            'pack_name' => $c->pack_name,
            'pack_credits' => (int) $c->pack_credits,
            'validity_type' => $c->validity_type,
            'amount_bdt' => (float) $c->amount_bdt,
            'onboarding_fee_bdt' => (float) $c->onboarding_fee_bdt,
            'total_bdt' => (float) $c->total_bdt,
            'status' => $c->status,
            'method' => $c->method,
            'sender_number' => $c->sender_number,
            'trx_id' => $c->trx_id,
            'admin_note' => $c->admin_note,
            'when' => DhakaTime::fmt($c->created_at, 'd M Y, h:i A'),
            'decided_at' => DhakaTime::fmt($c->decided_at, 'd M Y, h:i A'),
        ];
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user && $user->business_id, 403);
        $biz = (int) $user->business_id;
        $wallet = $this->tokens->ensureWallet($biz);

        $ledger = TokenLedger::where('business_id', $biz)->latest('id')->paginate(25);
        $orderInvoices = Order::where('business_id', $biz)
            ->whereIn('id', $ledger->getCollection()->pluck('order_id')->filter()->unique()->all())
            ->pluck('invoice_no', 'id');

        $openClaim = $this->claims->openClaim($biz);
        $pastClaims = SellerPaymentClaim::where('business_id', $biz)
            ->whereIn('status', [SellerPaymentClaim::APPROVED, SellerPaymentClaim::REJECTED])
            ->latest('id')->limit(5)->get();

        $preselected = null;
        $packParam = $request->query('pack');
        if ($packParam && is_numeric($packParam)) {
            $found = PointPack::where('id', (int) $packParam)->where('is_active', true)->first();
            $preselected = $found?->id;
        }

        return Inertia::render('Billing/Index', [
            'balance' => (int) $wallet->balance,
            'pointsPerOrder' => $this->tokens->pointsPerOrder(),
            'threshold' => $this->tokens->lowThreshold(),
            'blocked' => $this->tokens->blocked($biz),
            'low' => $this->tokens->isLow($biz),
            'pack' => $wallet->pack_id ? [
                'name' => $wallet->packName(),
                'validity_type' => $wallet->pack_snapshot['validity_type'] ?? null,
                'cycle_start' => DhakaTime::fmt($wallet->cycle_start, 'd M Y'),
                'cycle_end' => DhakaTime::fmt($wallet->cycle_end, 'd M Y'),
                'wallet_expires_at' => DhakaTime::fmt($wallet->wallet_expires_at, 'd M Y'),
            ] : null,
            'packs' => PointPack::where('is_active', true)->orderBy('sort')->orderBy('id')->get()
                ->map(fn ($p) => [
                    'id' => $p->id, 'name' => $p->name, 'price_bdt' => (float) $p->price_bdt,
                    'credits' => (int) $p->credits, 'validity_type' => $p->validity_type,
                    'rate' => round($p->rate(), 2),
                ])->all(),
            'onboardingFee' => $this->tokens->onboardingFee(),
            'firstPurchase' => $wallet->first_purchase_at !== null,
            'paymentInstructions' => $this->tokens->paymentInstructions(),
            // The PLATFORM's own payment numbers (Settings → Payment
            // Gateway, platform scope) — how this seller pays US.
            'platformPayment' => app(\App\Services\PaymentGatewayService::class)->enabledNumbers(null),
            'openClaim' => $openClaim ? $this->claimView($openClaim) : null,
            'pastClaims' => $pastClaims->map(fn ($c) => $this->claimView($c))->all(),
            'preselectedPackId' => $preselected,
            'ledger' => [
                'data' => $ledger->getCollection()->map(fn ($r) => [
                    'id' => $r->id, 'kind' => $r->kind, 'amount' => (int) $r->amount,
                    'balance_after' => (int) $r->balance_after, 'note' => $r->note,
                    'invoice' => $r->order_id ? ($orderInvoices[$r->order_id] ?? null) : null,
                    'when' => DhakaTime::fmt($r->created_at, 'd M Y, h:i A'),
                ])->all(),
                'current_page' => $ledger->currentPage(),
                'last_page' => $ledger->lastPage(),
                'total' => $ledger->total(),
            ],
        ]);
    }

    /** Start a pack purchase: creates the pending payment claim. */
    public function storeClaim(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->business_id, 403);
        $data = $request->validate([
            'pack_id' => ['required', 'exists:point_packs,id'],
        ]);
        $pack = PointPack::findOrFail((int) $data['pack_id']);
        try {
            $this->claims->createClaim((int) $user->business_id, $pack);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment request created — pay the total below and submit your payment details.');
    }

    /** Submit the offline payment details for the open claim. */
    public function submitClaim(Request $request, SellerPaymentClaim $claim): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user && $user->business_id, 403);
        abort_unless((int) $claim->business_id === (int) $user->business_id, 404);
        $data = $request->validate([
            'method' => ['required', 'in:bKash,Nagad'],
            'sender_number' => ['required', 'string', 'max:30'],
            'trx_id' => ['required', 'string', 'max:60'],
        ]);
        try {
            $this->claims->submitPayment($claim, $data['method'], $data['sender_number'], $data['trx_id']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment submitted — we will confirm it shortly.');
    }
}
