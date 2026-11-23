<?php

namespace App\Models;

use App\Services\MetaGraphService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shop's own connection to one Meta endpoint: a Facebook Page, a
 * WhatsApp phone number, or (schema-ready, parked) an Instagram
 * account. The token is stored encrypted and never leaves the server;
 * the browser only ever sees set-flags and the last 4 characters.
 *
 * Routing contract: UNIQUE(channel, external_id) means a Page/number
 * belongs to exactly one shop, so webhook intake is a single lookup
 * and two shops can never receive each other's messages.
 */
class ChannelConnection extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'last_checked_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** The decrypted access token, or null when none is stored. */
    public function token(): ?string
    {
        return MetaGraphService::decrypt($this->token_enc);
    }

    /** Last 4 characters of the token, for display only. */
    public function tokenLast4(): ?string
    {
        $token = $this->token();

        return $token ? substr($token, -4) : null;
    }

    /** A disconnected row keeps its ids for reconnect prefill but must never route or send. */
    public function isLive(): bool
    {
        return $this->status !== 'disconnected';
    }

    /**
     * The config shape MetaGraphService sends with: the same keys the
     * legacy global channels.{channel} array carried, so the send path
     * treats a row and the legacy config identically.
     */
    public function config(): array
    {
        return [
            'account_id' => (string) $this->external_id,
            'token' => $this->token(),
            'waba_id' => (string) ($this->parent_external_id ?? ''),
            'page_name' => (string) ($this->display_name ?? ''),
            'phone_number' => (string) (($this->meta['display_phone_number'] ?? null) ?: ($this->display_name ?? '')),
            'username' => (string) ($this->display_name ?? ''),
            'connection_id' => (int) $this->id,
        ];
    }

    /** Webhook intake lookup: the live connection that owns this Page ID / phone_number_id. */
    public static function forWebhook(string $channel, string $externalId): ?self
    {
        if ($externalId === '') {
            return null;
        }

        return self::where('channel', $channel)
            ->where('external_id', $externalId)
            ->where('status', '!=', 'disconnected')
            ->first();
    }

    /**
     * A shop's current connection for a channel: the newest live row.
     * A shop may hold older rows (an old Page beside a new one); the
     * newest is the one Settings shows and the send fallback uses.
     * Conversations keep their own stamp, so this only serves rows
     * that predate stamping.
     */
    public static function forBusiness(int $businessId, string $channel): ?self
    {
        if ($businessId <= 0) {
            return null;
        }

        return self::where('business_id', $businessId)
            ->where('channel', $channel)
            ->where('status', '!=', 'disconnected')
            ->orderByDesc('id')
            ->first();
    }
}
