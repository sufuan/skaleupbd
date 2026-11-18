<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRuleProposal extends Model
{
    protected $guarded = [];

    protected $casts = ['rule_data' => 'array'];
}
