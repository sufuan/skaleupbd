<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $guarded = [];

    protected $casts = ['last_message_at' => 'datetime', 'ai_paused' => 'boolean', 'needs_human' => 'boolean', 'state_data' => 'array', 'focus_at' => 'datetime',
        'first_inbound_at' => 'datetime', 'last_inbound_at' => 'datetime', 'last_outbound_at' => 'datetime', 'window_expires_at' => 'datetime', 'contact_shared' => 'boolean'];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
