<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderActivity extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime', 'meta' => 'array'];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
