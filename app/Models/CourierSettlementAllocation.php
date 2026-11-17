<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierSettlementAllocation extends Model
{
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'disputed' => 'boolean'];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CourierSettlement::class, 'settlement_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
