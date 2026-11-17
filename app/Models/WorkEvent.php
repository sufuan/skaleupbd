<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkEvent extends Model
{
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['created_at' => 'datetime'];
}
