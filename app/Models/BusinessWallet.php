<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessWallet extends Model
{
    protected $guarded = [];

    protected $casts = [
        'pack_snapshot' => 'array',
        'balance' => 'integer',
        'cycle_start' => 'datetime',
        'cycle_end' => 'datetime',
        'wallet_expires_at' => 'datetime',
        'first_purchase_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(PointPack::class, 'pack_id');
    }

    /** Name of the pack the seller is currently on (snapshot wins). */
    public function packName(): ?string
    {
        return $this->pack_snapshot['name'] ?? $this->pack?->name;
    }
}
