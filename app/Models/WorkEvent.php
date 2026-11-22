<?php

namespace App\Models;

use App\Models\Concerns\StampsCreatedAt;
use Illuminate\Database\Eloquent\Model;

class WorkEvent extends Model
{
    use StampsCreatedAt;
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['created_at' => 'datetime'];
}
