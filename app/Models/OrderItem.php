<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $guarded = [];

    protected $casts = ['price' => 'decimal:2', 'cost' => 'decimal:2'];
}
