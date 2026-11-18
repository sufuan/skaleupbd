<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerPayment extends Model
{
    protected $guarded = [];

    protected $casts = ['paid_at' => 'datetime'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SellerSubscription::class, 'subscription_id');
    }
}
