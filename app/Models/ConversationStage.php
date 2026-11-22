<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationStage extends Model
{
    protected $table = 'conversation_stage';

    protected $guarded = [];

    protected $casts = [
        'reached_at_1' => 'datetime',
        'reached_at_2' => 'datetime',
        'reached_at_3' => 'datetime',
        'reached_at_4' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
