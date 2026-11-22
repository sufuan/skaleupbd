<?php

namespace App\Models;

use App\Models\Concerns\StampsCreatedAt;
use Illuminate\Database\Eloquent\Model;

class CallLog extends Model
{
    use StampsCreatedAt;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];
}
