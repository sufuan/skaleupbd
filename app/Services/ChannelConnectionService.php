<?php

namespace App\Services;

use App\Support\DhakaTime;
use App\Models\Business;
use App\Models\ChannelConnection;
use App\Models\Comment;
use App\Models\Conversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ধাপ ৫: everything about a shop's own channel connections that is not
 * the raw Graph call itself — the legacy-config migration, the health
 * check that keeps a row's status honest, the Facebook app
 * subscription that makes webhooks flow, disconnect, and the
 * token-free view the Settings page renders.
 */
class ChannelConnectionService
{
    private const API = 'https://graph.facebook.com/v21.0';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly MetaGraphService $graph,
    ) {}

    /**
     * Move the platform's existing global connection (channels.* keys)
     * into channel_connections rows owned by the shop the old routing
     * pointed at, and stamp that shop's historical conversations and
     * comments. Idempotent: a Page/number that already has a row is
     * left exactly as it is. The global keys stay in place afterwards —
     * the send path keeps them as a transition fallback.
     */
    public function migrateLegacyGlobal(): int
    {
        if (! Schema::hasTable('channel_connections')) {
            return 0;
        }
        $biz = (int) $this->settings->get('channels.business_id', 0);
        if ($biz <= 0 || ! Business::whereKey($biz)->exists()) {
            $biz = (int) (Business::orderBy('id')->value('id') ?? 0);
        }
        if ($biz <= 0) {
            return 0;
        }
        $created = 0;
        $nameKeys = ['facebook' => 'page_name', 'whatsapp' => 'phone_number', 'instagram' => 'username'];
        foreach ($nameKeys as $channel => $nameKey) {
            $cfg = (array) $this->settings->get("channels.{$channel}", []);
            $accountId = trim((string) ($cfg['account_id'] ?? ''));
            $tokenEnc = (string) ($cfg['token_enc'] ?? '');
            if ($accountId === '' || $tokenEnc === '') {
                continue;
            }
            $row = ChannelConnection::where('channel', $channel)->where('external_id', $accountId)->first();
            if ($row && (int) $row->business_id !== $biz) {
                continue; // the Page already belongs to another shop — never reassign it here
            }
            if ($row && $row->status !== 'disconnected') {
                // The platform re-saved its global config (a token
                // rotation): refresh the row so the platform shop's
                // connection never goes stale against its own form.
                $row->forceFill([
                    'token_enc' => $tokenEnc,
                    'parent_external_id' => $channel === 'whatsapp' ? (($cfg['waba_id'] ?? '') ?: $row->parent_external_id) : $row->parent_external_id,
                    'display_name' => (($cfg[$nameKey] ?? '') ?: $row->display_name),
                ])->save();
            }
            if (! $row) {
                $row = ChannelConnection::create([
                    'business_id' => $biz,
                    'channel' => $channel,
                    'external_id' => $accountId,
                    'parent_external_id' => $channel === 'whatsapp' ? (($cfg['waba_id'] ?? '') ?: null) : null,
                    'display_name' => (($cfg[$nameKey] ?? '') ?: null),
                    'token_enc' => $tokenEnc,
                    'token_type' => 'manual',
                    'connect_method' => 'manual',
                    'status' => 'connected',
                    'meta' => $channel === 'whatsapp' && ! empty($cfg['phone_number'])
                        ? ['display_phone_number' => (string) $cfg['phone_number']] : null,
                ]);
                $created++;
            }
            if ($row->status !== 'disconnected') {
                Conversation::where('business_id', $biz)->where('channel', $channel)
                    ->whereNull('channel_connection_id')->update(['channel_connection_id' => $row->id]);
                if ($channel !== 'whatsapp') {
                    Comment::where('business_id', $biz)->where('channel', $channel)
                        ->whereNull('channel_connection_id')->update(['channel_connection_id' => $row->id]);
                }
            }
        }

        return $created;
    }

    /**
     * Run the Meta check against one connection row and record the
     * outcome on the row: a pass marks it connected and refreshes the
     * display name from Meta's own answer; a failure marks it error
     * with Meta's message, which is what the Settings card and the
     * Work Inbox channel_broken rule read.
     */
    public function check(ChannelConnection $conn): array
    {
        $result = $this->graph->checkConfig((string) $conn->channel, $conn->config());
        $patch = [
            'last_checked_at' => now(),
            'status' => $result['ok'] ? 'connected' : 'error',
            'last_error' => $result['ok'] ? null : (string) $result['detail'],
        ];
        if ($result['ok'] && ! empty($result['name'])) {
            $patch['display_name'] = (string) $result['name'];
        }
        if ($result['ok'] && ! empty($result['phone'])) {
            $meta = (array) ($conn->meta ?? []);
            $meta['display_phone_number'] = (string) $result['phone'];
            $patch['meta'] = $meta;
        }
        $conn->forceFill($patch)->save();

        return $result;
    }

    /**
     * Subscribe the platform app to a Page so its messages and feed
     * comments actually reach the webhook. Uses the Page's own token.
     */
    public function subscribeFacebook(ChannelConnection $conn): array
    {
        $token = $conn->token();
        if (! $token) {
            return ['ok' => false, 'detail' => 'Token is missing'];
        }
        try {
            $res = Http::timeout(12)->post(self::API . "/{$conn->external_id}/subscribed_apps", [
                'access_token' => $token,
                'subscribed_fields' => 'messages,messaging_postbacks,feed',
            ]);
            if ($res->successful() && $res->json('success')) {
                return ['ok' => true, 'detail' => 'Subscribed'];
            }

            return ['ok' => false, 'detail' => (string) ($res->json('error.message') ?? ('HTTP ' . $res->status()))];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** Best-effort unsubscribe on disconnect; our drop rule covers a failure. */
    public function unsubscribeFacebook(ChannelConnection $conn): void
    {
        $token = $conn->token();
        if (! $token) {
            return;
        }
        try {
            Http::timeout(12)->delete(self::API . "/{$conn->external_id}/subscribed_apps", [
                'access_token' => $token,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Channel unsubscribe failed', ['connection_id' => $conn->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Disconnect: unsubscribe first (Facebook), then mark the row
     * disconnected and CLEAR the token — a disconnected row must not
     * keep a usable credential. Ids stay so reconnect can prefill.
     */
    public function disconnect(ChannelConnection $conn): void
    {
        if ($conn->channel === 'facebook') {
            $this->unsubscribeFacebook($conn);
        }
        $conn->forceFill([
            'status' => 'disconnected',
            'token_enc' => null,
            'last_error' => null,
        ])->save();
    }

    /**
     * The token-free view of one connection for the Settings page:
     * ids and display data, a token set-flag and (outside view-as) the
     * token's last 4 characters. The token value never crosses.
     */
    public function view(ChannelConnection $conn, bool $redactLast4 = false): array
    {
        return [
            'id' => (int) $conn->id,
            'channel' => (string) $conn->channel,
            'external_id' => (string) $conn->external_id,
            'parent_external_id' => (string) ($conn->parent_external_id ?? ''),
            'display_name' => (string) ($conn->display_name ?? ''),
            'phone_number' => (string) (($conn->meta['display_phone_number'] ?? null) ?? ''),
            'token_set' => ! empty($conn->token_enc),
            'token_last4' => $redactLast4 ? null : $conn->tokenLast4(),
            'status' => (string) $conn->status,
            'last_error' => (string) ($conn->last_error ?? ''),
            'last_checked_at' => DhakaTime::fmt($conn->last_checked_at, 'd M Y, h:i A'),
            'connect_method' => (string) $conn->connect_method,
        ];
    }

    /** The seller Channels prop: the shop's current Facebook + WhatsApp rows (Instagram is parked). */
    public function viewFor(int $businessId, bool $redactLast4 = false): ?array
    {
        if ($businessId <= 0) {
            return null;
        }
        $out = [];
        foreach (['facebook', 'whatsapp'] as $channel) {
            $conn = ChannelConnection::forBusiness($businessId, $channel);
            $out[$channel] = $conn ? $this->view($conn, $redactLast4) : null;
        }

        return $out;
    }
}
