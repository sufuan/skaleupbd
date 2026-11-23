<?php

// Goes in: app/Models/WebhookDelivery.php (new file).

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'connection_id', 'event_id', 'topic', 'attempt', 'outcome', 'http_status',
        'duration_ms', 'error', 'response_excerpt', 'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'next_retry_at' => 'datetime',
            'attempt' => 'integer',
            'http_status' => 'integer',
        ];
    }
}
