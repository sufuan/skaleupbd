<?php

namespace App\Services;

use App\Models\ChannelConnection;
use App\Models\ChatMessage;
use App\Models\Comment;
use App\Models\Conversation;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to Meta's Graph API for Messenger, WhatsApp and Instagram.
 * Since ধাপ ৫ each shop sends through its OWN channel connection
 * (channel_connections row, token encrypted); the platform's original
 * global config in Settings (channels.*) remains only as a transition
 * fallback for rows that predate a stamped connection. Sending only
 * happens for conversations that carry a real external id (a PSID /
 * WhatsApp number / IG id) — demo and seeded conversations are skipped.
 */
class MetaGraphService
{
    private const API = 'https://graph.facebook.com/v21.0';

    /** Meta error codes that mean the access token itself is dead (invalid / expired / invalidated). */
    private const AUTH_ERROR_CODES = [102, 190, 463, 467, 2500];

    private const NOT_CONNECTED = 'Channel is not connected - connect it in Settings, Messaging Channels';

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

    /**
     * Connection-first resolution (ধাপ ৫): the conversation's own
     * stamped connection, else the shop's current connection for the
     * channel, else the legacy global config. Returns the config array
     * plus the connection row it came from (null for the legacy path),
     * so a dead token can be marked on the row that owns it.
     *
     * @return array{0: array, 1: ?ChannelConnection}
     */
    private function resolveForConversation(Conversation $conv): array
    {
        $conn = null;
        if ($conv->channel_connection_id) {
            $candidate = ChannelConnection::find($conv->channel_connection_id);
            if ($candidate && (int) $candidate->business_id === (int) $conv->business_id && $candidate->isLive()) {
                $conn = $candidate;
            }
        }
        if (! $conn) {
            $conn = ChannelConnection::forBusiness((int) $conv->business_id, (string) $conv->channel);
        }
        if ($conn && $conn->token()) {
            return [$conn->config(), $conn];
        }

        return [$this->channelConfig((string) $conv->channel), $conn];
    }

    /** @return array{0: array, 1: ?ChannelConnection} */
    private function resolveForComment(Comment $comment): array
    {
        $conn = null;
        if ($comment->channel_connection_id) {
            $candidate = ChannelConnection::find($comment->channel_connection_id);
            if ($candidate && (int) $candidate->business_id === (int) $comment->business_id && $candidate->isLive()) {
                $conn = $candidate;
            }
        }
        if (! $conn) {
            $conn = ChannelConnection::forBusiness((int) $comment->business_id, (string) $comment->channel);
        }
        if ($conn && $conn->token()) {
            return [$conn->config(), $conn];
        }

        return [$this->channelConfig((string) $comment->channel), $conn];
    }

    /** The send config for a conversation: its own shop's connection first (ধাপ ৫). */
    public function configForConversation(Conversation $conv): array
    {
        return $this->resolveForConversation($conv)[0];
    }

    /**
     * A send rejected with an auth-class Meta error means the
     * connection's token is dead: mark the row so the Settings card
     * and the Work Inbox channel_broken rule can surface it. Only a
     * row currently marked connected is flipped (never resurrect or
     * re-mark a row a human already handled).
     */
    private function noteConnectionFailure(?ChannelConnection $conn, mixed $code, ?string $error): void
    {
        if (! $conn || $conn->status !== 'connected') {
            return;
        }
        if (! in_array((int) $code, self::AUTH_ERROR_CODES, true)) {
            return;
        }
        $conn->forceFill([
            'status' => 'error',
            'last_error' => $error,
            'last_checked_at' => now(),
        ])->save();
    }

    /**
     * The full Graph error payload of a failed call, extracted in ONE
     * place for every send path (Messenger, WhatsApp, Instagram,
     * comments, images). 'message' keeps the historical behaviour
     * (error.message, else the HTTP status); the machine fields ride
     * along so a failed send can be diagnosed from our own records —
     * the bare message text ("Application does not have permission
     * for this action") does not say WHICH permission is missing,
     * the code / subcode / fbtrace_id do.
     *
     * @return array{message: string, code: mixed, subcode: mixed, type: mixed, fbtrace_id: mixed}
     */
    private function graphError(Response $res): array
    {
        return [
            'message' => (string) ($res->json('error.message') ?? ('HTTP ' . $res->status())),
            'code' => $res->json('error.code'),
            'subcode' => $res->json('error.error_subcode'),
            'type' => $res->json('error.type'),
            'fbtrace_id' => $res->json('error.fbtrace_id') ?? $res->json('fbtrace_id'),
        ];
    }

    /**
     * Persist a failed send's full Meta error on the message row:
     * send_error keeps holding ONLY the message text (the inbox
     * "Failed to send" display reads it), the machine fields land in
     * their own meta keys, null when Meta did not send them.
     *
     * @param  array<string, mixed>  $meta
     * @param  array{code: mixed, subcode: mixed, type: mixed, fbtrace_id: mixed}|null  $detail
     * @return array<string, mixed>
     */
    private function metaWithSendError(array $meta, string $error, ?array $detail): array
    {
        $meta['send_error'] = $error;
        $meta['send_error_code'] = $detail['code'] ?? null;
        $meta['send_error_subcode'] = $detail['subcode'] ?? null;
        $meta['send_error_type'] = $detail['type'] ?? null;
        $meta['send_fbtrace_id'] = $detail['fbtrace_id'] ?? null;

        return $meta;
    }

    /**
     * Log context for a failed send: the message text plus the
     * machine fields, so the log line alone identifies the cause.
     *
     * @param  array{code: mixed, subcode: mixed, type: mixed, fbtrace_id: mixed}|null  $detail
     * @return array<string, mixed>
     */
    private function sendFailureLogContext(ChatMessage $msg, string $error, ?array $detail): array
    {
        return [
            'message_id' => $msg->id,
            'error' => $error,
            'error_code' => $detail['code'] ?? null,
            'error_subcode' => $detail['subcode'] ?? null,
            'error_type' => $detail['type'] ?? null,
            'fbtrace_id' => $detail['fbtrace_id'] ?? null,
        ];
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
        [$ok, $error, $sentId, $detail] = $this->sendText($conv, (string) $msg->body);
        $meta = (array) ($msg->meta ?? []);
        $meta['send_status'] = $ok ? 'delivered_to_meta' : 'failed';
        if ($error) {
            $meta = $this->metaWithSendError($meta, $error, $detail);
        }
        $msg->meta = $meta;
        if ($ok && $sentId) {
            // Remember Meta's id for this send — when the echo comes back
            // we can tell our own message apart from a human's.
            $msg->external_id = $sentId;
        }
        $msg->save();
        if (! $ok) {
            Log::warning('Meta send failed', $this->sendFailureLogContext($msg, (string) $error, $detail));
        }

        return [$ok, $error];
    }

    /** @return array{0: bool, 1: ?string, 2: ?string, 3: ?array} ok, error, Meta message id, Meta error detail */
    public function sendText(Conversation $conv, string $text): array
    {
        [$cfg, $conn] = $this->resolveForConversation($conv);
        if (empty($cfg['token']) || empty($cfg['account_id'])) {
            return [false, self::NOT_CONNECTED, null, null];
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
                $mid = $conv->channel === 'whatsapp'
                    ? (string) ($res->json('messages.0.id') ?? '')
                    : (string) ($res->json('message_id') ?? '');

                return [true, null, $mid !== '' ? $mid : null, null];
            }

            $detail = $this->graphError($res);
            $this->noteConnectionFailure($conn, $detail['code'], $detail['message']);

            return [false, $detail['message'], null, $detail];
        } catch (\Throwable $e) {
            return [false, $e->getMessage(), null, null];
        }
    }

    /**
     * Send a message with Messenger/Instagram quick-reply buttons.
     * $options: list of ['title' => ..., 'payload' => ...] (max 13, title ≤ 20 chars).
     *
     * @return array{0: bool, 1: ?string, 2: ?string, 3: ?array} ok, error, Meta message id, Meta error detail
     */
    public function sendQuickReplies(Conversation $conv, string $text, array $options): array
    {
        [$cfg, $conn] = $this->resolveForConversation($conv);
        if (empty($cfg['token']) || empty($cfg['account_id'])) {
            return [false, self::NOT_CONNECTED, null, null];
        }
        if ($conv->channel === 'whatsapp') {
            // WhatsApp has no quick replies in this shape — plain text.
            return $this->sendText($conv, $text);
        }
        try {
            $quick = [];
            foreach (array_slice($options, 0, 13) as $o) {
                $quick[] = ['content_type' => 'text', 'title' => mb_substr((string) ($o['title'] ?? ''), 0, 20), 'payload' => (string) ($o['payload'] ?? '')];
            }
            $res = Http::timeout(12)->post(self::API . "/{$cfg['account_id']}/messages?access_token=" . urlencode($cfg['token']), [
                'recipient' => ['id' => $conv->external_id],
                'messaging_type' => 'RESPONSE',
                'message' => ['text' => $text, 'quick_replies' => $quick],
            ]);
            if ($res->successful()) {
                $mid = (string) ($res->json('message_id') ?? '');

                return [true, null, $mid !== '' ? $mid : null, null];
            }

            $detail = $this->graphError($res);
            $this->noteConnectionFailure($conn, $detail['code'], $detail['message']);

            return [false, $detail['message'], null, $detail];
        } catch (\Throwable $e) {
            return [false, $e->getMessage(), null, null];
        }
    }

    /** dispatch() twin for quick-reply messages: same gating and bookkeeping. */
    public function dispatchQuickReplies(ChatMessage $msg, array $options): array
    {
        if ($msg->direction !== 'out' || $msg->status !== 'sent') {
            return [true, null];
        }
        $conv = $msg->conversation ?? Conversation::find($msg->conversation_id);
        if (! $conv || ! $conv->external_id) {
            return [true, null]; // not a live channel conversation
        }
        if ($conv->channel === 'whatsapp') {
            return $this->dispatch($msg);
        }
        [$ok, $error, $sentId, $detail] = $this->sendQuickReplies($conv, (string) $msg->body, $options);
        $meta = (array) ($msg->meta ?? []);
        $meta['send_status'] = $ok ? 'delivered_to_meta' : 'failed';
        if ($error) {
            $meta = $this->metaWithSendError($meta, $error, $detail);
        }
        $msg->meta = $meta;
        if ($ok && $sentId) {
            $msg->external_id = $sentId;
        }
        $msg->save();
        if (! $ok) {
            Log::warning('Meta quick-reply send failed', $this->sendFailureLogContext($msg, (string) $error, $detail));
        }

        return [$ok, $error];
    }

    /** Public reply under a Facebook/Instagram comment. */
    public function replyToComment(Comment $comment, string $text): array
    {
        if (! $comment->external_id) {
            return [true, null];
        }
        [$cfg, $conn] = $this->resolveForComment($comment);
        if (empty($cfg['token'])) {
            return [false, self::NOT_CONNECTED];
        }
        try {
            $res = Http::timeout(12)->post(self::API . "/{$comment->external_id}/comments?access_token=" . urlencode($cfg['token']), [
                'message' => $text,
            ]);
            if ($res->successful()) {
                return [true, null];
            }
            $detail = $this->graphError($res);
            $this->noteConnectionFailure($conn, $detail['code'], $detail['message']);

            return [false, $detail['message']];
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
        [$cfg, $conn] = $this->resolveForComment($comment);
        if (empty($cfg['token']) || empty($cfg['account_id'])) {
            return [false, self::NOT_CONNECTED];
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

            if ($res->successful()) {
                return [true, null];
            }
            $detail = $this->graphError($res);
            $this->noteConnectionFailure($conn, $detail['code'], $detail['message']);

            return [false, $detail['message']];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    public function setCommentHidden(Comment $comment, bool $hidden): array
    {
        if (! $comment->external_id) {
            return [true, null];
        }
        [$cfg, $conn] = $this->resolveForComment($comment);
        if (empty($cfg['token'])) {
            return [false, self::NOT_CONNECTED];
        }
        try {
            $res = Http::timeout(12)->post(self::API . "/{$comment->external_id}?access_token=" . urlencode($cfg['token']), [
                'is_hidden' => $hidden,
            ]);
            if ($res->successful()) {
                return [true, null];
            }
            $detail = $this->graphError($res);
            $this->noteConnectionFailure($conn, $detail['code'], $detail['message']);

            return [false, $detail['message']];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /** Live check used by the Settings "Check connection" button (platform global config). */
    public function check(string $channel): array
    {
        return $this->checkConfig($channel, $this->channelConfig($channel));
    }

    /**
     * The same check against an explicit config (a shop's own
     * channel_connections row). Also returns the display name / phone
     * number Meta answered with, so a connection row can show Meta's
     * own name for the Page/number instead of typed text.
     */
    public function checkConfig(string $channel, array $cfg): array
    {
        if (empty($cfg['token']) || empty($cfg['account_id'])) {
            return ['ok' => false, 'detail' => 'Token or account id is missing', 'name' => null, 'phone' => null];
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
                    return ['ok' => true, 'detail' => 'Connected - Messenger is reachable', 'name' => null, 'phone' => null];
                }

                return ['ok' => false, 'detail' => (string) ($res->json('error.message') ?? ('HTTP ' . $res->status())), 'name' => null, 'phone' => null];
            }
            $fields = $channel === 'whatsapp' ? 'display_phone_number,verified_name' : 'username,name';
            $res = Http::timeout(12)->get(self::API . "/{$cfg['account_id']}", [
                'fields' => $fields,
                'access_token' => $cfg['token'],
            ]);
            if ($res->successful()) {
                $name = $res->json('verified_name') ?? $res->json('name') ?? $res->json('username');
                $phone = $channel === 'whatsapp' ? $res->json('display_phone_number') : null;
                $label = $name ?? $phone;

                return ['ok' => true, 'detail' => 'Connected' . ($label ? " - {$label}" : ''), 'name' => $name, 'phone' => $phone];
            }

            return ['ok' => false, 'detail' => (string) ($res->json('error.message') ?? ('HTTP ' . $res->status())), 'name' => null, 'phone' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage(), 'name' => null, 'phone' => null];
        }
    }

    /** Send an image by public URL (photo confirmation). Returns [ok, error]. */
    public function sendImage(Conversation $conv, string $url): array
    {
        [$cfg, $conn] = $this->resolveForConversation($conv);
        if (empty($cfg['token']) || empty($cfg['account_id']) || !$conv->external_id) {
            return [false, self::NOT_CONNECTED];
        }
        try {
            if ($conv->channel === 'whatsapp') {
                $to = preg_replace('/\D+/', '', (string) ($conv->external_id ?: $conv->customer_phone));
                $res = Http::timeout(12)->withToken($cfg['token'])->post(self::API . "/{$cfg['account_id']}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'image',
                    'image' => ['link' => $url],
                ]);
            } else {
                $res = Http::timeout(12)->post(self::API . "/{$cfg['account_id']}/messages?access_token=" . urlencode($cfg['token']), [
                    'recipient' => ['id' => $conv->external_id],
                    'messaging_type' => 'RESPONSE',
                    'message' => ['attachment' => ['type' => 'image', 'payload' => ['url' => $url, 'is_reusable' => true]]],
                ]);
            }

            if ($res->successful()) {
                return [true, null];
            }
            $detail = $this->graphError($res);
            $this->noteConnectionFailure($conn, $detail['code'], $detail['message']);

            return [false, $detail['message']];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * Best-effort display name for a Messenger/IG sender id. Pass the
     * receiving connection's config when the caller has one (webhook
     * intake), so the lookup uses the owning shop's own token.
     */
    public function senderName(string $channel, string $externalId, ?array $cfg = null): ?string
    {
        $cfg = $cfg ?? $this->channelConfig($channel);
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
