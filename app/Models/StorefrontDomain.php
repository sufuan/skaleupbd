<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontDomain extends Model
{
    protected $fillable = [
        'business_id', 'storefront_id', 'domain', 'status', 'txt_token',
        'is_primary', 'verified_at', 'added_at',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
        'added_at' => 'datetime',
    ];

    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isLive(): bool
    {
        return in_array($this->status, ['verified', 'active'], true);
    }
}
