<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierPickupStore extends Model
{
    protected $guarded = [];
    protected $casts = ['is_default' => 'boolean'];

    public function method(): BelongsTo
    {
        return $this->belongsTo(CourierMethod::class, 'courier_method_id');
    }
}
