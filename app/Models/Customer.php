<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = [];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Lifetime value: what this customer has actually received - the sum
     * of their DELIVERED order totals. Computed at read time on purpose:
     * the stored lifetime_value column was never written by any code
     * path, so it sat at 0 forever while two list screens sorted by it.
     * A live sum cannot drift from the orders themselves.
     */
    public function lifetimeValue(): float
    {
        return (float) $this->orders()->where('status', 'Delivered')->sum('grand_total');
    }
}
