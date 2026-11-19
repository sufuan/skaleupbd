<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'business_id', 'user_id', 'date', 'check_in_at', 'check_out_at',
        'break_started_at', 'break_minutes', 'late_minutes', 'status',
        'auto_closed', 'edited_by',
    ];

    protected $casts = [
        'date' => 'date',
        'check_in_at' => 'datetime',
        'check_out_at' => 'datetime',
        'break_started_at' => 'datetime',
        'auto_closed' => 'boolean',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Net worked minutes for the day (0 while still open). */
    public function workMinutes(): int
    {
        if (! $this->check_in_at || ! $this->check_out_at) {
            return 0;
        }

        return max(0, (int) $this->check_in_at->diffInMinutes($this->check_out_at) - (int) $this->break_minutes);
    }
}
