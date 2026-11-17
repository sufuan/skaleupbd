<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierAssignmentRule extends Model
{
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function method(): BelongsTo
    {
        return $this->belongsTo(CourierMethod::class, 'courier_method_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function pickupStore(): BelongsTo
    {
        return $this->belongsTo(CourierPickupStore::class);
    }
}
