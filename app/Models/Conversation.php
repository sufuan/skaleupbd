<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $guarded = [];

    protected $casts = ['last_message_at' => 'datetime', 'ai_paused' => 'boolean', 'needs_human' => 'boolean', 'state_data' => 'array', 'focus_at' => 'datetime'];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
