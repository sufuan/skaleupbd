<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PointPack;
use App\Models\TokenLedger;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M19 — the seller's Points & Billing page: balance, active pack,
 * usage ledger and how to pay. Any signed-in member of the shop may
 * view it (so staff can tell the owner to top up); buying is recorded
 * by the platform admin in v1.
 */
class BillingController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

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

        return Inertia::render('Billing/Index', [
            'balance' => (int) $wallet->balance,
            'pointsPerOrder' => $this->tokens->pointsPerOrder(),
            'threshold' => $this->tokens->lowThreshold(),
            'blocked' => $this->tokens->blocked($biz),
            'low' => $this->tokens->isLow($biz),
            'pack' => $wallet->pack_id ? [
                'name' => $wallet->packName(),
                'validity_type' => $wallet->pack_snapshot['validity_type'] ?? null,
                'cycle_start' => $wallet->cycle_start?->format('d M Y'),
                'cycle_end' => $wallet->cycle_end?->format('d M Y'),
                'wallet_expires_at' => $wallet->wallet_expires_at?->format('d M Y'),
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
            'ledger' => [
                'data' => $ledger->getCollection()->map(fn ($r) => [
                    'id' => $r->id, 'kind' => $r->kind, 'amount' => (int) $r->amount,
                    'balance_after' => (int) $r->balance_after, 'note' => $r->note,
                    'invoice' => $r->order_id ? ($orderInvoices[$r->order_id] ?? null) : null,
                    'when' => $r->created_at?->format('d M Y, h:i A'),
                ])->all(),
                'current_page' => $ledger->currentPage(),
                'last_page' => $ledger->lastPage(),
                'total' => $ledger->total(),
            ],
        ]);
    }
}
