<?php

namespace App\Models;

use App\Models\Concerns\StampsCreatedAt;
use Illuminate\Database\Eloquent\Model;

class OrderActivity extends Model
{
    use StampsCreatedAt;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime', 'meta' => 'array'];

    public function order(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
