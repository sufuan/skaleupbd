<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    protected $guarded = [];

    protected $casts = ['private_reply_at' => 'datetime', 'hidden_by_ai' => 'boolean', 'liked' => 'boolean'];

    public function replies(): HasMany
    {
        return $this->hasMany(CommentReply::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AiRule::class, 'ai_rule_id');
    }
}
