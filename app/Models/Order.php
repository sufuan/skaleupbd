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
        'cross_sale' => 'boolean',
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
    public const EXTRA_STATUSES = ['Incomplete', 'Pending Return', 'Pending Cancel', 'Lost'];
    public const PROCESSING_STATUSES = ['Processing', 'Good But No Response', 'No Response', 'Advance Payment', 'On Hold', 'Stock Out', 'Cancel'];
    public const CANCEL_REASONS = ['Customer Request', 'Wrong Number', 'Phone Off', 'No Answer', 'Out of Stock', 'Duplicate Order', 'Fraud', 'Price Issue', 'Advance Payment Issue', 'Other'];

    /**
     * The Change Status dropdown options for this order — a pure
     * function of the order's current status (Round 2 finding 9: the
     * same order must never show a different set on a later load).
     * Inside the Pending/Processing flow the dropdown works on the
     * processing sub-statuses; outside it, the current status leads,
     * followed by the sub-statuses it can still move to (Cancel is not
     * offered there — a shipped/delivered order cannot be cancelled).
     *
     * @return list<string>
     */
    public function changeStatusOptions(): array
    {
        if (in_array($this->status, ['Pending', 'Processing'], true)) {
            return self::PROCESSING_STATUSES;
        }

        return array_values(array_unique([
            $this->status,
            ...array_filter(self::PROCESSING_STATUSES, fn (string $s) => $s !== $this->status && $s !== 'Cancel'),
        ]));
    }

    /**
     * True when this order's courier has no status feed of its own, so
     * a human action is the only way its delivery can ever be recorded:
     * no courier method at all (hand delivery), a manual courier, or an
     * API courier running on the mock driver in test mode. A real API
     * courier reports delivery itself and must never be completed by
     * hand. (The Scan To Delivery guard and the order page's Mark as
     * Delivered action share this one definition.)
     */
    public function deliverableByHand(): bool
    {
        $method = $this->courierMethod;
        if (! $method) {
            return true;
        }
        $driver = \App\Services\Couriers\DriverFactory::for($method);

        return $driver instanceof \App\Services\Couriers\ManualDriver
            || $driver instanceof \App\Services\Couriers\MockDriver;
    }

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

    /**
     * The district used for courier zone decisions. The New Order form (like
     * the prototype) has no district field — the zone is chosen through the
     * delivery method itself ("… — Inside Dhaka" / "… — Outside Dhaka"), so
     * when no district was stored, read it back from the method.
     */
    public function effectiveDistrict(): ?string
    {
        if (! empty($this->district)) {
            return $this->district;
        }
        $m = (string) $this->delivery_method;
        if (str_contains($m, 'Outside Dhaka')) {
            return 'Outside Dhaka';
        }
        if (str_contains($m, 'Inside Dhaka')) {
            return 'Dhaka';
        }

        return $this->district;
    }

    public function getInvoiceAttribute(): ?string
    {
        return $this->invoice_no;
    }

    /** Orders store the street address in customer_address; courier drivers
     *  and delivery screens read $order->address. Without this accessor the
     *  drivers composed the recipient address from the district alone and
     *  Pathao rejected bookings ("recipient_address must be at least 10
     *  characters") for every district shorter than 10 characters. */
    public function getAddressAttribute(): ?string
    {
        return $this->customer_address;
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
