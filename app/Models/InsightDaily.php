<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InsightDaily extends Model
{
    protected $table = 'insight_daily';

    protected $guarded = [];

    protected $casts = [
        'cohort_date' => 'date',
        'reconciled' => 'boolean',
    ];
}
