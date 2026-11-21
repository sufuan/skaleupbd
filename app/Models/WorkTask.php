<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkTask extends Model
{
    protected $guarded = [];

    protected $casts = [
        'due_at' => 'datetime',
        'done_at' => 'datetime',
        'closed_at' => 'datetime',
        'escalated_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
        'escalated' => 'boolean',
    ];

    public const OPEN_STATUSES = ['open', 'in_progress'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true)
            && $this->due_at !== null && $this->due_at->isPast();
    }
}
