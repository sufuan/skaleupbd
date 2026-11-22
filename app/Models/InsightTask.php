<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsightTask extends Model
{
    protected $guarded = [];

    protected $casts = [
        'title_params' => 'array',
        'task_date' => 'date',
        'est_value' => 'decimal:2',
        'generated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(InsightTaskMember::class, 'task_id');
    }
}
