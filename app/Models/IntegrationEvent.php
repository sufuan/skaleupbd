<?php

// Goes in: app/Models/IntegrationEvent.php (new file).

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationEvent extends Model
{
    protected $table = 'integration_events';

    protected $fillable = [
        'connection_id', 'direction', 'topic', 'event_uuid', 'subject_id', 'dedupe_key', 'payload',
        'status', 'attempts', 'next_attempt_at', 'locked_until', 'error',
    ];

    protected function casts(): array
    {
        return [
            'next_attempt_at' => 'datetime',
            'locked_until' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
