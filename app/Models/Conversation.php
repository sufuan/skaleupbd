<?php

namespace App\Models;

use App\Jobs\SendWebPushJob;
use App\Services\WorkInboxService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $guarded = [];

    protected $casts = ['last_message_at' => 'datetime', 'ai_paused' => 'boolean', 'needs_human' => 'boolean', 'state_data' => 'array', 'focus_at' => 'datetime',
        'first_inbound_at' => 'datetime', 'last_inbound_at' => 'datetime', 'last_outbound_at' => 'datetime', 'window_expires_at' => 'datetime', 'contact_shared' => 'boolean'];

    /**
     * The ONE choke point for the needs_human browser push (owner
     * order 2026-11-25): every code path that flags a conversation
     * for a human (Rule Brain, media job, voice job) saves through
     * this model, so watching the saved change here covers them all
     * without editing each call site. Fires only on the false-to-true
     * flip, and only while the shop's ai_handover Work Inbox rule has
     * its push toggle on. Recipients: the shop owner, plus any staff
     * user who has already spoken in this conversation.
     */
    protected static function booted(): void
    {
        static::saved(function (Conversation $conv) {
            if (! $conv->wasChanged('needs_human') || ! $conv->needs_human || ! $conv->business_id) {
                return;
            }
            $biz = (int) $conv->business_id;
            $cfg = app(WorkInboxService::class)->ruleSettings($biz)['ai_handover'] ?? null;
            if (! $cfg || ! ($cfg['push'] ?? false)) {
                return;
            }
            $channel = ['facebook' => 'Messenger', 'whatsapp' => 'WhatsApp', 'instagram' => 'Instagram'][$conv->channel] ?? $conv->channel;
            $body = trim((string) $conv->customer_name) . ' (' . $channel . ')';
            $recipients = collect();
            if ($owner = User::shopOwner($biz)) {
                $recipients->push($owner->id);
            }
            $staffIds = ChatMessage::where('conversation_id', $conv->id)
                ->where('sender', 'staff')->whereNotNull('user_id')
                ->distinct()->pluck('user_id');
            $recipients = $recipients->merge($staffIds)->unique()->values();
            foreach ($recipients as $userId) {
                SendWebPushJob::dispatch((int) $userId, 'Human reply needed', $body, '/messages/inbox');
            }
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
