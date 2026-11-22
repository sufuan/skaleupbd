<?php

namespace App\Models;

use App\Models\Concerns\StampsCreatedAt;
use Illuminate\Database\Eloquent\Model;

class OrderStatusHistory extends Model
{
    use StampsCreatedAt;
    protected $table = 'order_status_history';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];
}
