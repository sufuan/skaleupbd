<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Business;
use App\Models\PointPack;
use App\Models\SellerPaymentClaim;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * M19 purchase flow — the claim lifecycle behind the self-serve pack
 * purchase: a seller picks a pack (registration or Points & Billing),
 * pays offline, submits the payment details, and a platform admin
 * approves or rejects. Approval runs TokenService::purchase, so the
 * money rows and the points land exactly like an admin-recorded sale.
 *
 * One open claim per business: creating a claim while one is open
 * returns the open one (never a duplicate). Approval is guarded on the
 * claim status inside a locked transaction, so a double approve can
 * never credit twice.
 */
class PaymentClaimService
{
    public function __construct(private readonly TokenService $tokens) {}

    public function openClaim(int $businessId): ?SellerPaymentClaim
    {
        return SellerPaymentClaim::where('business_id', $businessId)
            ->whereIn('status', [SellerPaymentClaim::PENDING_PAYMENT, SellerPaymentClaim::PENDING_REVIEW])
            ->latest('id')->first();
    }

    /**
     * Start a claim for a pack. The onboarding fee is part of the total
     * only when the business has never completed a purchase.
     */
    public function createClaim(int $businessId, PointPack $pack): SellerPaymentClaim
    {
        if (! $pack->is_active) {
            throw new DomainException('This pack is not active — pick another pack.');
        }

        return DB::transaction(function () use ($businessId, $pack) {
            $open = SellerPaymentClaim::where('business_id', $businessId)
                ->whereIn('status', [SellerPaymentClaim::PENDING_PAYMENT, SellerPaymentClaim::PENDING_REVIEW])
                ->lockForUpdate()->first();
            if ($open) {
                return $open;
            }

            $wallet = $this->tokens->ensureWallet($businessId);
            $fee = $wallet->first_purchase_at === null ? $this->tokens->onboardingFee() : 0.0;

            return SellerPaymentClaim::create([
                'business_id' => $businessId,
                'pack_id' => $pack->id,
                'pack_name' => $pack->name,
                'pack_credits' => (int) $pack->credits,
                'validity_type' => $pack->validity_type,
                'amount_bdt' => (float) $pack->price_bdt,
                'onboarding_fee_bdt' => $fee,
                'total_bdt' => (float) $pack->price_bdt + $fee,
                'status' => SellerPaymentClaim::PENDING_PAYMENT,
            ]);
        });
    }

    /** The seller sends the offline payment details — now awaiting review. */
    public function submitPayment(SellerPaymentClaim $claim, string $method, string $senderNumber, string $trxId): SellerPaymentClaim
    {
        return DB::transaction(function () use ($claim, $method, $senderNumber, $trxId) {
            $claim = SellerPaymentClaim::where('id', $claim->id)->lockForUpdate()->firstOrFail();
            if ($claim->status !== SellerPaymentClaim::PENDING_PAYMENT) {
                throw new DomainException('This payment request is not waiting for payment details.');
            }
            $claim->update([
                'method' => $method,
                'sender_number' => $senderNumber,
                'trx_id' => $trxId,
                'status' => SellerPaymentClaim::PENDING_REVIEW,
            ]);

            $shop = $claim->business?->name ?? 'A seller';
            // Platform notice: business_id stays NULL on purpose, so
            // only platform admins ever see it (claims are reviewed on
            // /admin/claims). See AppNotification::visibleTo.
            AppNotification::create([
                'kind' => 'payment',
                'title' => 'Payment waiting for confirmation',
                'body' => "{$shop} paid ৳".number_format((float) $claim->total_bdt)." for {$claim->pack_name} via {$method} (TrxID {$trxId})",
                'link' => '/admin/claims',
            ]);

            return $claim;
        });
    }

    /**
     * Approve a submitted claim: the pack purchase runs through
     * TokenService (money rows + points + onboarding fee on a first
     * purchase) and the claim is marked approved — once only.
     */
    public function approve(SellerPaymentClaim $claim, ?int $adminId): SellerPaymentClaim
    {
        return DB::transaction(function () use ($claim, $adminId) {
            $claim = SellerPaymentClaim::where('id', $claim->id)->lockForUpdate()->firstOrFail();
            if ($claim->status === SellerPaymentClaim::APPROVED) {
                return $claim; // idempotent — a second approve credits nothing
            }
            if ($claim->status !== SellerPaymentClaim::PENDING_REVIEW) {
                throw new DomainException('Only a submitted payment can be approved.');
            }

            $this->tokens->purchase(
                (int) $claim->business_id,
                (int) $claim->pack_id,
                (float) $claim->amount_bdt,
                $claim->method ?: 'bKash',
                "Payment claim #{$claim->id} approved (TrxID {$claim->trx_id})",
                $adminId,
            );

            $claim->update([
                'status' => SellerPaymentClaim::APPROVED,
                'decided_by' => $adminId,
                'decided_at' => now(),
            ]);

            // Combined admin action (owner's workflow): the payment
            // approval IS the account approval. If this seller's
            // business is still pending, the same click activates
            // it — points credited above set first_purchase_at, so
            // the shop address unlocks at the same moment.
            $business = Business::find((int) $claim->business_id);
            if ($business && $business->status === 'pending') {
                $business->update([
                    'status' => 'approved',
                    'approved_by' => $adminId,
                    'approved_at' => now(),
                    'status_note' => null,
                ]);
                // Same audit trail as a direct approval (Hunt Y4 F10).
                \App\Models\SellerDecisionLog::record($business->id, $adminId, 'approved', 'Approved with payment claim #'.$claim->id);
            }

            return $claim;
        });
    }

    /** Reject an open claim with a note the seller will see. No credit. */
    public function reject(SellerPaymentClaim $claim, ?int $adminId, string $note): SellerPaymentClaim
    {
        return DB::transaction(function () use ($claim, $adminId, $note) {
            $claim = SellerPaymentClaim::where('id', $claim->id)->lockForUpdate()->firstOrFail();
            if ($claim->status === SellerPaymentClaim::REJECTED) {
                return $claim; // idempotent
            }
            if ($claim->status === SellerPaymentClaim::APPROVED) {
                throw new DomainException('An approved payment cannot be rejected.');
            }
            $claim->update([
                'status' => SellerPaymentClaim::REJECTED,
                'admin_note' => $note,
                'decided_by' => $adminId,
                'decided_at' => now(),
            ]);

            return $claim;
        });
    }
}
