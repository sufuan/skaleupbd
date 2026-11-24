<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'total' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PurchaseAttachment::class);
    }

    /** Money already allocated to this purchase from supplier payments. */
    public function paidAmount(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    /** Due is derived, never stored: total − allocated payments. Quick Stock has no supplier bill, so nothing is ever owed on it. */
    public function due(): float
    {
        if ($this->kind !== 'bill') {
            return 0.0;
        }

        return round(max(0, (float) $this->total - $this->paidAmount()), 2);
    }

    public function isFullyReceived(): bool
    {
        return ! $this->items()->whereRaw('qty_received + qty_damaged < qty_ordered')->exists();
    }

    /** Value of the goods still on their way (ordered − received − damaged) × cost. */
    public function notReceivedValue(): float
    {
        return round((float) $this->items()
            ->selectRaw('SUM((qty_ordered - qty_received - qty_damaged) * unit_cost) as v')
            ->value('v'), 2);
    }

    /** Overdue = still owes money and the supplier's credit terms have run out. */
    public function isOverdue(?string $today = null): bool
    {
        if ($this->kind !== 'bill' || ! $this->supplier_id || $this->due() <= 0) {
            return false;
        }
        $terms = (int) ($this->supplier?->terms_days ?? 30);
        $dueDate = $this->date->copy()->addDays($terms)->toDateString();

        return $dueDate < ($today ?? now('Asia/Dhaka')->toDateString());
    }

    /** Receive status label, exactly as the lists show it. */
    public function receiveStatus(): string
    {
        if ($this->isFullyReceived()) {
            return 'Received';
        }
        // Hunt G-8: once any goods are in (received or damaged) the
        // purchase is partially received, not "Not received".
        $touched = $this->items()->whereRaw('qty_received + qty_damaged > 0')->exists();

        return $touched ? 'Partially received' : 'Not received';
    }
}
