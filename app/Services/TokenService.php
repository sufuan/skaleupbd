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

        return DB::transaction(function () use ($businessId, $pack, $paidAmount, $method, $note, $byUserId) {
            $wallet = BusinessWallet::where('business_id', $businessId)->lockForUpdate()->first()
                ?? $this->ensureWallet($businessId);

            $first = $wallet->first_purchase_at === null;
            $paid = $paidAmount ?? (float) $pack->price_bdt;

            if ($paid > 0) {
                SellerPayment::create([
                    'business_id' => $businessId, 'amount' => $paid, 'method' => $method,
                    'note' => $note ?: ($pack->name.' pack purchase'), 'paid_at' => now(),
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
    }

    /** Free credit granted by the platform (owner grants, corrections). */
    public function manualCredit(int $businessId, int $amount, string $note, ?int $byUserId): BusinessWallet
    {
        if ($amount === 0) {
            throw new DomainException('Credit amount cannot be zero.');
        }

        return DB::transaction(function () use ($businessId, $amount, $note, $byUserId) {
            $wallet = BusinessWallet::where('business_id', $businessId)->lockForUpdate()->first()
                ?? $this->ensureWallet($businessId);
            $this->writeRow($wallet, 'manual_credit', $amount, [
                'note' => $note, 'created_by' => $byUserId,
            ]);

            return $wallet;
        });
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
     * once only): zeroes the bucket with a refund row and records the
     * money going back as a negative payment.
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
            if ((int) $wallet->balance !== 0) {
                $this->writeRow($wallet, 'refund', -(int) $wallet->balance, [
                    'note' => 'First purchase refunded (7-day window)', 'created_by' => $byUserId,
                ]);
            }
            $price = (float) ($wallet->pack_snapshot['price_bdt'] ?? 0);
            if ($price > 0) {
                SellerPayment::create([
                    'business_id' => $businessId, 'amount' => -$price, 'method' => 'Refund',
                    'note' => 'Refund — first purchase (7-day window)', 'paid_at' => now(),
                ]);
            }
            $feeRow = SellerPayment::where('business_id', $businessId)
                ->where('note', 'like', 'Onboarding fee%')->where('amount', '>', 0)->first();
            if ($feeRow) {
                SellerPayment::create([
                    'business_id' => $businessId, 'amount' => -abs((float) $feeRow->amount), 'method' => 'Refund',
                    'note' => 'Refund — onboarding fee (7-day window)', 'paid_at' => now(),
                ]);
            }
            $wallet->pack_id = null;
            $wallet->cycle_start = null;
            $wallet->cycle_end = null;
            $wallet->wallet_expires_at = null;
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
