<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Comment;
use App\Models\Conversation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to Meta's Graph API for Messenger, WhatsApp and Instagram.
 * Channel credentials live in Settings (API Connection → Messaging
 * Channels); tokens are stored encrypted. Sending only happens for
 * conversations that carry a real external id (a PSID / WhatsApp
 * number / IG id) — demo and seeded conversations are skipped.
 */
class MetaGraphService
{
    private const API = 'https://graph.facebook.com/v21.0';

    public function __construct(private readonly SettingsService $settings) {}

    public static function encrypt(?string $plain): ?string
    {
        return $plain ? Crypt::encryptString($plain) : null;
    }

    public static function decrypt(?string $stored): ?string
    {
        if (! $stored) {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return $stored; // tolerate a plain value saved before encryption
        }
    }

    public function channelConfig(string $channel): array
    {
        $cfg = (array) $this->settings->get("channels.{$channel}", []);
        $cfg['token'] = self::decrypt($cfg['token_enc'] ?? null);

        return $cfg;
    }

    public function isConnected(string $channel): bool
    {
        $cfg = $this->channelConfig($channel);

        return ! empty($cfg['token']) && ! empty($cfg['account_id']);
    }

    /** Send a chat message out to the customer. Returns [ok, error|null]. */
    public function dispatch(ChatMessage $msg): array
    {
        if ($msg->direction !== 'out' || $msg->status !== 'sent') {
            return [true, null];
        }
        $conv = $msg->conversation ?? Conversation::find($msg->conversation_id);
        if (! $conv || ! $conv->external_id) {
            return [true, null]; // not a live channel conversation
        }
        [$ok, $error] = $this->sendText($conv, (string) $msg->body);
        $meta = (array) ($msg->meta ?? []);
        $meta['send_status'] = $ok ? 'delivered_to_meta' : 'failed';
        if ($error) {
            $meta['send_error'] = $error;
        }
        $msg->meta = $meta;
        $msg->save();
        if (! $ok) {
            Log::warning('Meta send failed', ['message_id' => $msg->id, 'error' => $error]);
        }

        return [$ok, $error];
    }

    /** @return array{0: bool, 1: ?string} */
    public function sendText(Conversation $conv, string $text): array
    {
        $cfg = $this->channelConfig($conv->channel);
        if (empty($cfg['token']) || empty($cfg['account_id'])) {
            return [false, 'Channel is not connected in Settings'];
        }
        try {
            if ($conv->channel === 'whatsapp') {
                $to = preg_replace('/\D+/', '', (string) ($conv->external_id ?: $conv->customer_phone));
                $res = Http::timeout(12)->withToken($cfg['token'])->post(self::API . "/{$cfg['account_id']}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => $text],
                ]);
            } else {
                // Messenger (facebook) and Instagram share the Send API shape.
                $res = Http::timeout(12)->post(self::API . "/{$cfg['account_id']}/messages?access_token=" . urlencode($cfg['token']), [
                    'recipient' => ['id' => $conv->external_id],
                    'messaging_type' => 'RESPONSE',
                    'message' => ['text' => $text],
                ]);
            }
            if ($res->successful()) {
                return [true, null];
            }

            return [false, (string) ($res->json('error.message') ?? ('HTTP ' . $res->status()))];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /** Public reply under a Facebook/Instagram comment. */
    public function replyToComment(Comment $comment, string $text): array
    {
        if (! $comment->external_id) {
            return [true, null];
        }
        $cfg = $this->channelConfig($comment->channel);
        if (empty($cfg['token'])) {
            return [false, 'Channel is not connected in Settings'];
        }
        try {
            $res = Http::timeout(12)->post(self::API . "/{$comment->external_id}/comments?access_token=" . urlencode($cfg['token']), [
                'message' => $text,
            ]);

            return $res->successful() ? [true, null] : [false, (string) ($res->json('error.message') ?? ('HTTP ' . $res->status()))];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /** The one private reply allowed per comment (within 7 days). */
    public function privateReplyToComment(Comment $comment, string $text): array
    {
        if (! $comment->external_id) {
            return [true, null];
        }
        $cfg = $this->channelConfig($comment->channel);
        if (empty($cfg['token']) || empty($cfg['account_id'])) {
            return [false, 'Channel is not connected in Settings'];
        }
        try {
            if ($comment->channel === 'instagram') {
                if (! $comment->author_ref) {
                    return [false, 'No Instagram user id on this comment'];
                }
                $res = Http::timeout(12)->post(self::API . "/{$cfg['account_id']}/messages?access_token=" . urlencode($cfg['token']), [
                    'recipient' => ['id' => $comment->author_ref],
                    'messaging_type' => 'RESPONSE',
                    'message' => ['text' => $text],
                ]);
            } else {
                $res = Http::timeout(12)->post(self::API . "/{$comment->external_id}/private_replies?access_token=" . urlencode($cfg['token']), [
                    'message' => $text,
                ]);
            }

            return $res->successful() ? [true, null] : [false, (string) ($res->json('error.message') ?? ('HTTP ' . $res->status()))];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    public function setCommentHidden(Comment $comment, bool $hidden): array
    {
        if (! $comment->external_id) {
            return [true, null];
        }
        $cfg = $this->channelConfig($comment->channel);
        if (empty($cfg['token'])) {
            return [false, 'Channel is not connected in Settings'];
        }
        try {
            $res = Http::timeout(12)->post(self::API . "/{$comment->external_id}?access_token=" . urlencode($cfg['token']), [
                'is_hidden' => $hidden,
            ]);

            return $res->successful() ? [true, null] : [false, (string) ($res->json('error.message') ?? ('HTTP ' . $res->status()))];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /** Live check used by the Settings "Check connection" button. */
    public function check(string $channel): array
    {
        $cfg = $this->channelConfig($channel);
        if (empty($cfg['token']) || empty($cfg['account_id'])) {
            return ['ok' => false, 'detail' => 'Token or account id is missing'];
        }
        try {
            if ($channel === 'facebook') {
                // A Page token proves itself on the conversations edge;
                // the page node itself is gated behind pages_read_engagement.
                $res = Http::timeout(12)->get(self::API . "/{$cfg['account_id']}/conversations", [
                    'limit' => 1,
                    'access_token' => $cfg['token'],
                ]);
                if ($res->successful()) {
                    return ['ok' => true, 'detail' => 'Connected — Messenger is reachable'];
                }

                return ['ok' => false, 'detail' => (string) ($res->json('error.message') ?? ('HTTP ' . $res->status()))];
            }
            $fields = $channel === 'whatsapp' ? 'display_phone_number,verified_name' : 'username,name';
            $res = Http::timeout(12)->get(self::API . "/{$cfg['account_id']}", [
                'fields' => $fields,
                'access_token' => $cfg['token'],
            ]);
            if ($res->successful()) {
                $name = $res->json('name') ?? $res->json('verified_name') ?? $res->json('username') ?? $res->json('display_phone_number');

                return ['ok' => true, 'detail' => 'Connected' . ($name ? " — {$name}" : '')];
            }

            return ['ok' => false, 'detail' => (string) ($res->json('error.message') ?? ('HTTP ' . $res->status()))];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** Best-effort display name for a Messenger/IG sender id. */
    public function senderName(string $channel, string $externalId): ?string
    {
        $cfg = $this->channelConfig($channel);
        if (empty($cfg['token']) || $channel === 'whatsapp') {
            return null;
        }
        try {
            $res = Http::timeout(8)->get(self::API . "/{$externalId}", [
                'fields' => 'name',
                'access_token' => $cfg['token'],
            ]);

            return $res->successful() ? $res->json('name') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
