<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    protected $fillable = [
        'run_id', 'user_id', 'basic', 'working_days', 'absent_days',
        'absent_cut', 'advance_cut', 'other_adjust', 'net',
    ];

    protected $casts = [
        'basic' => 'decimal:2',
        'absent_cut' => 'decimal:2',
        'advance_cut' => 'decimal:2',
        'other_adjust' => 'decimal:2',
        'net' => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recomputeNet(): void
    {
        $this->net = round((float) $this->basic - (float) $this->absent_cut - (float) $this->advance_cut + (float) $this->other_adjust, 2);
    }
}
