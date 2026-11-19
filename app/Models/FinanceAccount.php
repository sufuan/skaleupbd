<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceAccount extends Model
{
    protected $guarded = [];
    protected $casts = ['opening_balance' => 'decimal:2', 'active' => 'boolean'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class, 'account_id');
    }

    /** Live balance: opening + money in − money out, voided rows excluded. */
    public function balance(): float
    {
        $in = (float) $this->transactions()->where('direction', 'in')->whereNull('voided_at')->sum('amount');
        $out = (float) $this->transactions()->where('direction', 'out')->whereNull('voided_at')->sum('amount');
        return round((float) $this->opening_balance + $in - $out, 2);
    }
}
