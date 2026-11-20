<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $guarded = [];

    protected $casts = ['meta' => 'array', 'attachment_meta' => 'array'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AiRule::class, 'ai_rule_id');
    }
}
