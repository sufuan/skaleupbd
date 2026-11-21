<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only token ledger (M19). Rows are never edited or deleted;
 * the wallet balance must always equal SUM(amount) for the business.
 */
class TokenLedger extends Model
{
    protected $table = 'token_ledger';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['amount' => 'integer', 'balance_after' => 'integer', 'created_at' => 'datetime'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    protected static function booted(): void
    {
        static::creating(function (TokenLedger $row) {
            $row->created_at ??= now();
        });
    }
}
