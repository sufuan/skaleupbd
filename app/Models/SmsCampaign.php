<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsCampaign extends Model
{
    protected $guarded = [];

    protected $casts = ['total_cost' => 'float', 'parts' => 'integer', 'recipients' => 'integer'];
}
