<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tags' => 'array',
        'attachments' => 'array',
        'is_preorder' => 'boolean',
        'preorder_notified' => 'boolean',
        'approved_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
        'notify_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'delivery_charge' => 'decimal:2',
        'advance' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    public const STATUSES = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Returned', 'Cancelled'];
    public const EXTRA_STATUSES = ['Incomplete', 'Pending Return', 'Pending Cancel', 'Partial', 'Lost'];
    public const PROCESSING_STATUSES = ['Processing', 'Good But No Response', 'No Response', 'Advance Payment', 'On Hold', 'Stock Out', 'Cancel'];
    public const CANCEL_REASONS = ['Customer Request', 'Wrong Number', 'Phone Off', 'No Answer', 'Out of Stock', 'Duplicate Order', 'Fraud', 'Price Issue', 'Advance Payment Issue', 'Other'];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(OrderActivity::class)->orderBy('created_at');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function getInvoiceAttribute(): ?string
    {
        return $this->invoice_no;
    }

    public function getDueAttribute(): float
    {
        return max(0, (float) $this->grand_total - (float) $this->advance);
    }

    public function logHistory(?string $from, string $to, $actor = null, ?string $note = null): void
    {
        $this->history()->create([
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'note' => $note,
        ]);
    }

    public function logActivity(string $kind, string $text, $actor = null, ?array $meta = null): void
    {
        $this->activities()->create([
            'kind' => $kind,
            'text' => $text,
            'actor_name' => $actor?->name,
            'meta' => $meta,
        ]);
    }
    public function courierMethod(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CourierMethod::class);
    }

    public function pickupStore(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CourierPickupStore::class);
    }

    public function bookingLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CourierBookingLog::class);
    }

    /** COD the courier must collect for this order. */
    public function codAmount(): float
    {
        return max(0, (float) $this->grand_total - (float) $this->advance);
    }
}
