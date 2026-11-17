<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierBookingLog extends Model
{
    protected $guarded = [];
    protected $casts = ['request_payload' => 'array', 'response_payload' => 'array'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(CourierMethod::class, 'courier_method_id');
    }
}
