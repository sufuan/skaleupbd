<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourierSettlement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2', 'due_bills' => 'decimal:2', 'paid_bills' => 'decimal:2',
        'charges' => 'decimal:2', 'total' => 'decimal:2',
        'ready_at' => 'datetime', 'paid_at' => 'datetime',
    ];

    // Named courierMethod: the table also has a string `method` column
    // (the payment channel, e.g. Bank/bKash) which would shadow a method() relation.
    public function courierMethod(): BelongsTo
    {
        return $this->belongsTo(CourierMethod::class, 'courier_method_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CourierSettlementAllocation::class, 'settlement_id');
    }
}
