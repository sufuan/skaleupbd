<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $guarded = [];

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function available(): int
    {
        return max(0, (int) $this->stock_qty - (int) $this->reserved_qty);
    }

    /** A variant's own price when set, else the product's current price. */
    public function effectivePrice(): float
    {
        return $this->price !== null ? (float) $this->price : $this->product->effectivePrice();
    }
}
