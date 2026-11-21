<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M19 — a seller's self-serve pack purchase request. Created as
 * pending_payment (at registration or from Points & Billing), the
 * seller submits offline payment details (pending_review), and a
 * platform admin approves (points credited via TokenService) or
 * rejects with a note. Decided claims stay as history.
 */
class SellerPaymentClaim extends Model
{
    public const PENDING_PAYMENT = 'pending_payment';

    public const PENDING_REVIEW = 'pending_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = [];

    protected $casts = [
        'amount_bdt' => 'float',
        'onboarding_fee_bdt' => 'float',
        'total_bdt' => 'float',
        'pack_credits' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(PointPack::class, 'pack_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING_PAYMENT, self::PENDING_REVIEW], true);
    }
}
