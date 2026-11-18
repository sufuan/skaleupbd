<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerSubscription extends Model
{
    protected $guarded = [];

    protected $casts = ['starts_at' => 'date', 'expires_at' => 'date'];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isLive(): bool
    {
        return $this->status === 'active' && $this->expires_at->gte(today());
    }
}
