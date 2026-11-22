<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty_ordered' => 'integer',
        'qty_received' => 'integer',
        'qty_damaged' => 'integer',
        'unit_cost' => 'decimal:2',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Left = ordered − received − damaged (damaged never counts as received). */
    public function left(): int
    {
        return max(0, $this->qty_ordered - $this->qty_received - $this->qty_damaged);
    }

    public function lineTotal(): float
    {
        return round($this->qty_ordered * (float) $this->unit_cost, 2);
    }
}
