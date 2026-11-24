<?php

namespace App\Services;

use App\Models\BusinessWallet;
use App\Models\Order;
use App\Models\PointPack;
use App\Models\SellerPayment;
use App\Models\TokenLedger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * M19 Pricing — the token engine.
 *
 * Sellers buy point packs with money. A token is deducted the moment a
 * unique order ENTERS the database (inside OrderService's creation
 * transaction, exactly once per order via an idempotency key) and is
 * never refunded by status changes. Monthly packs live in a 30-day
 * bucket that resets; yearly packs are a wallet whose credit carries
 * 12 months. At zero balance (enforcement on) the account is view-only.
 *
 * The ledger is append-only and the wallet balance must always equal
 * SUM(token_ledger.amount) for the business.
 */
class TokenService
{
    public const BLOCK_MESSAGE = 'Not enough points to take a new order. Please buy a points pack to continue.';

    public function __construct(private readonly SettingsService $settings) {}

    // ---------- platform settings (Settings → Seller Packages) ----------

    public function pointsPerOrder(): int
    {
        return max(1, (int) $this->settings->get('pricing.points_per_order', 1));
    }

    public function onboardingFee(): float
    {
        return max(0, (float) $this->settings->get('pricing.onboarding_fee_bdt', 1000));
    }

    public function lowThreshold(): int
    {
        return max(0, (int) $this->settings->get('pricing.low_balance_threshold', 20));
    }

    public function enforcementOn(): bool
    {
        return (bool) $this->settings->get('pricing.enforcement_enabled', true);
    }

    public function paymentInstructions(): string
    {
        return (string) $this->settings->get('pricing.payment_instructions', '');
    }

    // ---------- wallet ----------

    public function ensureWallet(int $businessId): BusinessWallet
    {
        return BusinessWallet::firstOrCreate(
            ['business_id' => $businessId],
            ['balance' => 0, 'status' => 'active'],
        );
    }

    public function wallet(int $businessId): ?BusinessWallet
    {
        return BusinessWallet::where('business_id', $businessId)->first();
    }

    public function balance(int $businessId): int
    {
        return (int) (BusinessWallet::where('business_id', $businessId)->value('balance') ?? 0);
    }

    /** True when a new order may enter for this business. */
    public function canAfford(int $businessId): bool
    {
        if (! $this->enforcementOn()) {
            return true;
        }
        $wallet = $this->wallet($businessId);
        if (! $wallet) {
            return true; // unprovisioned legacy business — never brick
        }

        return $wallet->balance >= $this->pointsPerOrder();
    }

    /** True when the account is in the view-only blocked state. */
    public function blocked(int $businessId): bool
    {
        return $this->enforcementOn() && ! $this->canAfford($businessId)
            && $this->wallet($businessId) !== null;
    }

    /** Low (but not blocked) — drives the warnings before the stop. */
    public function isLow(int $businessId): bool
    {
        if (! $this->enforcementOn() || $this->blocked($businessId)) {
            return false;
        }
        $wallet = $this->wallet($businessId);

        return $wallet !== null && $wallet->balance <= $this->lowThreshold();
    }

    // ---------- ledger ----------

    private function writeRow(BusinessWallet $wallet, string $kind, int $amount, array $extra = []): TokenLedger
    {
        $wallet->balance += $amount;
        $wallet->save();

        return TokenLedger::create([
            'business_id' => $wallet->business_id,
            'kind' => $kind,
            'amount' => $amount,
            'balance_after' => $wallet->balance,
        ] + $extra);
    }

    // ---------- purchase / credit ----------

    /**
     * Record a pack purchase (v1: the platform admin records it after
     * the seller pays offline). Money lands in seller_payments; tokens
     * land per the cycle rules:
     *  - monthly: a fresh 30-day bucket REPLACES the old container —
     *    any remaining balance is forfeited via a reset row.
     *  - yearly_wallet: credits ADD to the wallet; the horizon extends
     *    to max(existing expiry, now) + 12 months. A monthly bucket's
     *    remaining balance is forfeited when switching to a wallet.
     * The one-time onboarding fee is charged on the FIRST purchase.
     */
    public function purchase(int $businessId, int $packId, ?float $paidAmount, string $method, ?string $note, ?int $byUserId): BusinessWallet
    {
        $pack = PointPack::findOrFail($packId);
        if (! $pack->is_active) {
            throw new DomainException('This pack is not active — pick another pack.');
        }

        $wallet = DB::transaction(function () use ($businessId, $pack, $paidAmount, $method, $note, $byUserId) {
            $wallet = BusinessWallet::where('business_id', $businessId)->lockForUpdate()->first()
                ?? $this->ensureWallet($businessId);

            $first = $wallet->first_purchase_at === null;
            $paid = $paidAmount ?? (float) $pack->price_bdt;

            if ($paid > 0) {
                SellerPayment::create([
                    'business_id' => $businessId, 'amount' => $paid, 'method' => $method,
                    // The pack name always leads the note — the platform's
                    // purchase history reads these rows back by it.
                    'note' => $pack->name.' pack purchase'.($note ? ' — '.$note : ''), 'paid_at' => now(),
                ]);
            }
            if ($first) {
                $fee = $this->onboardingFee();
                if ($fee > 0) {
                    SellerPayment::create([
                        'business_id' => $businessId, 'amount' => $fee, 'method' => $method,
                        'note' => 'Onboarding fee (one-time)', 'paid_at' => now(),
                    ]);
                }
                $wallet->first_purchase_at = now();
            }

            $switching = ($pack->validity_type === 'monthly')
                || ($wallet->cycle_end !== null); // wallet pack replacing a monthly bucket
            if ($switching && (int) $wallet->balance !== 0) {
                $this->writeRow($wallet, 'reset', -(int) $wallet->balance, [
                    'note' => 'Replaced by a new pack — remaining points do not carry over',
                    'created_by' => $byUserId,
                ]);
            }

            $this->writeRow($wallet, 'purchase', (int) $pack->credits, [
                'ref_type' => 'point_pack', 'ref_id' => $pack->id,
                'note' => $note ?: ('Pack purchase: '.$pack->name),
                'created_by' => $byUserId,
            ]);

            if ($pack->validity_type === 'monthly') {
                $wallet->cycle_start = now();
                $wallet->cycle_end = now()->addDays(30);
                $wallet->wallet_expires_at = null;
            } else {
                $base = ($wallet->wallet_expires_at && $wallet->wallet_expires_at->isFuture())
                    ? $wallet->wallet_expires_at->copy() : now();
                $wallet->wallet_expires_at = $base->addMonths(12);
                $wallet->cycle_start = null;
                $wallet->cycle_end = null;
            }
            $wallet->pack_id = $pack->id;
            $wallet->pack_snapshot = [
                'name' => $pack->name, 'price_bdt' => (float) $pack->price_bdt,
                'credits' => (int) $pack->credits, 'validity_type' => $pack->validity_type,
            ];
            $wallet->status = 'active';
            $wallet->save();

            return $wallet;
        });

        $this->resumeIntegrations($businessId);

        return $wallet;
    }

    /** Free credit granted by the platform (owner grants, corrections). */
    public function manualCredit(int $businessId, int $amount, string $note, ?int $byUserId): BusinessWallet
    {
        if ($amount === 0) {
            throw new DomainException('Credit amount cannot be zero.');
        }

        $wallet = DB::transaction(function () use ($businessId, $amount, $note, $byUserId) {
            $wallet = BusinessWallet::where('business_id', $businessId)->lockForUpdate()->first()
                ?? $this->ensureWallet($businessId);
            // A negative amount is a manual deduct — it may never take
            // the wallet below zero. This service is the only manual
            // adjustment door, so the floor lives here, not just in the
            // one controller that happens to call it today.
            if ($amount < 0 && (int) $wallet->balance + $amount < 0) {
                throw new DomainException('This adjustment would take the points balance below zero.');
            }
            $this->writeRow($wallet, 'manual_credit', $amount, [
                'note' => $note, 'created_by' => $byUserId,
            ]);

            return $wallet;
        });

        $this->resumeIntegrations($businessId);

        return $wallet;
    }

    /**
     * Website integration: a top-up is the moment a suspended connection
     * comes back to life. Wake the outbound webhook events that were
     * parked at zero points (inbound Woo events are re-queued by the
     * every-minute sweeper on the same balance signal). Runs after the
     * credit transaction has committed; best-effort - a resume failure
     * must never make a successful payment look failed.
     */
    private function resumeIntegrations(int $businessId): void
    {
        try {
            app(\App\Services\Integrations\OutboundWebhookService::class)->resumeForBusiness($businessId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('integration.resume_failed', [
                'business_id' => $businessId, 'error' => $e->getMessage(),
            ]);
        }
    }

    // ---------- deduction (OrderService creation path) ----------

    /**
     * Deduct the points for a freshly created order. Runs inside the
     * order-creation transaction; the unique idempotency key makes any
     * retry/replay deduct zero extra. No-ops when enforcement is off
     * or the business has no wallet yet (unprovisioned legacy rows).
     */
    public function deductForOrder(Order $order): void
    {
        if (! $this->enforcementOn()) {
            return;
        }
        $key = 'deduct:order:'.$order->id;
        if (TokenLedger::where('idempotency_key', $key)->exists()) {
            return;
        }
        $wallet = BusinessWallet::where('business_id', $order->business_id)->lockForUpdate()->first();
        if (! $wallet) {
            return;
        }
        $amount = $this->pointsPerOrder();
        try {
            $this->writeRow($wallet, 'deduct', -$amount, [
                'order_id' => $order->id,
                'points_per_order' => $amount,
                'idempotency_key' => $key,
                'note' => 'Order '.$order->invoice_no,
            ]);
        } catch (\Illuminate\Database\QueryException) {
            // Lost a race on the unique idempotency key — already deducted.
        }
    }

    // ---------- refund ----------

    /**
     * The 7-day refund of the FIRST purchase (platform-admin action,
     * once only). The refund undoes the first purchase ONLY: its money
     * goes back (the amount of the first purchase's own payment row,
     * plus the onboarding fee that first purchase carried) and its
     * points come out of the wallet. A later purchase is a separate
     * contract — its money, its points and the wallet's current pack
     * state stay untouched. (Reading the CURRENT pack snapshot here
     * refunded the latest pack's price and zeroed every point the
     * seller had, including points from later purchases.)
     */
    public function refundFirstPurchase(int $businessId, ?int $byUserId): BusinessWallet
    {
        return DB::transaction(function () use ($businessId, $byUserId) {
            $wallet = BusinessWallet::where('business_id', $businessId)->lockForUpdate()->first();
            if (! $wallet || ! $wallet->first_purchase_at) {
                throw new DomainException('This seller has no purchase to refund.');
            }
            if ($wallet->refunded_at) {
                throw new DomainException('The first purchase was already refunded.');
            }
            if (now()->gt($wallet->first_purchase_at->copy()->addDays(7))) {
                throw new DomainException('The 7-day refund window has passed.');
            }

            $purchases = TokenLedger::where('business_id', $businessId)->where('kind', 'purchase')
                ->orderBy('id')->get();
            $firstLedger = $purchases->first();

            // Money: the FIRST purchase's own payment row — purchase()
            // always leads the note with the pack name, so the earliest
            // positive "pack purchase" payment is the first purchase.
            $firstPayment = SellerPayment::where('business_id', $businessId)
                ->where('note', 'like', '% pack purchase%')->where('amount', '>', 0)
                ->orderBy('id')->first();
            if ($firstPayment) {
                SellerPayment::create([
                    'business_id' => $businessId, 'amount' => -(float) $firstPayment->amount, 'method' => 'Refund',
                    'note' => 'Refund — first purchase (7-day window)', 'paid_at' => now(),
                ]);
            }
            // The onboarding fee is charged on the first purchase only,
            // so an existing fee row is that purchase's fee.
            $feeRow = SellerPayment::where('business_id', $businessId)
                ->where('note', 'like', 'Onboarding fee%')->where('amount', '>', 0)->first();
            if ($feeRow) {
                SellerPayment::create([
                    'business_id' => $businessId, 'amount' => -abs((float) $feeRow->amount), 'method' => 'Refund',
                    'note' => 'Refund — onboarding fee (7-day window)', 'paid_at' => now(),
                ]);
            }

            // Points: reverse the first purchase's credits — but only
            // what still survives of them. A later pack purchase that
            // replaced the bucket already forfeited them through its
            // reset row (and an expired cycle's reset did the same), so
            // then there is nothing left to take; the later purchase's
            // points are never touched. The take is also capped at the
            // balance, so the wallet can never go below zero.
            if ($firstLedger) {
                $wiped = TokenLedger::where('business_id', $businessId)->where('kind', 'reset')
                    ->where('id', '>', $firstLedger->id)->exists();
                $owed = $wiped ? 0 : (int) $firstLedger->amount;
                $take = min($owed, (int) $wallet->balance);
                if ($take > 0) {
                    $this->writeRow($wallet, 'refund', -$take, [
                        'note' => 'First purchase refunded (7-day window)', 'created_by' => $byUserId,
                    ]);
                }
            }

            // Pack state belongs to the LATEST purchase: only clear it
            // when the first purchase is also the only one.
            if ($purchases->count() <= 1) {
                $wallet->pack_id = null;
                $wallet->cycle_start = null;
                $wallet->cycle_end = null;
                $wallet->wallet_expires_at = null;
            }
            $wallet->refunded_at = now();
            $wallet->save();

            return $wallet;
        });
    }

    public function refundEligible(int $businessId): bool
    {
        $wallet = $this->wallet($businessId);

        return $wallet !== null && $wallet->first_purchase_at !== null && $wallet->refunded_at === null
            && now()->lte($wallet->first_purchase_at->copy()->addDays(7));
    }

    // ---------- cycle expiry (daily job) ----------

    /**
     * Zero out monthly buckets whose 30-day cycle has ended and yearly
     * wallets past their 12-month horizon, each with a reconciling
     * reset row. Idempotent — cleared containers are skipped.
     */
    public function expireCycles(): array
    {
        $stats = ['monthly_reset' => 0, 'wallets_expired' => 0];
        $due = BusinessWallet::where(function ($q) {
            $q->whereNotNull('cycle_end')->where('cycle_end', '<', now());
        })->orWhere(function ($q) {
            $q->whereNotNull('wallet_expires_at')->where('wallet_expires_at', '<', now());
        })->pluck('id');

        foreach ($due as $id) {
            DB::transaction(function () use ($id, &$stats) {
                $wallet = BusinessWallet::where('id', $id)->lockForUpdate()->first();
                if (! $wallet) {
                    return;
                }
                $validity = $wallet->pack_snapshot['validity_type'] ?? null;
                $monthly = $wallet->cycle_end !== null && $wallet->cycle_end->isPast() && $validity !== 'yearly_wallet';
                $yearly = $wallet->wallet_expires_at !== null && $wallet->wallet_expires_at->isPast() && $validity !== 'monthly';
                if (! $monthly && ! $yearly) {
                    return;
                }
                if ((int) $wallet->balance !== 0) {
                    $this->writeRow($wallet, 'reset', -(int) $wallet->balance, [
                        'note' => $monthly
                            ? 'Monthly cycle ended — unused points expired'
                            : 'Wallet expired — unused points expired',
                    ]);
                }
                if ($monthly) {
                    $wallet->cycle_start = null;
                    $wallet->cycle_end = null;
                    $stats['monthly_reset']++;
                }
                if ($yearly) {
                    $wallet->wallet_expires_at = null;
                    $stats['wallets_expired']++;
                }
                $wallet->pack_id = null;
                $wallet->save();
            });
        }

        return $stats;
    }
}
