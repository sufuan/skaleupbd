<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierPayment extends Model
{
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'date' => 'date'];

    public function method(): BelongsTo
    {
        return $this->belongsTo(CourierMethod::class, 'courier_method_id');
    }
}
