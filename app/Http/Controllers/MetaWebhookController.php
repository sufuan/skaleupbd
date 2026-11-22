<?php

namespace App\Http\Controllers;

use App\Models\AiActivity;
use App\Models\Business;
use App\Models\ChatMessage;
use App\Models\Comment;
use App\Models\Conversation;
use App\Services\MetaGraphService;
use App\Services\RuleBrainService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Meta webhook: receives Messenger, WhatsApp and Instagram events,
 * feeds them into the Rule Brain, and sends auto replies back out
 * through the Graph API. Comments arrive as Page feed changes.
 */
class MetaWebhookController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly RuleBrainService $brain,
        private readonly MetaGraphService $graph,
    ) {}

    public function verify(Request $request): Response
    {
        $expected = (string) $this->settings->get('meta.verify_token', '');
        $ok = $request->query('hub_mode') === 'subscribe'
            && $expected !== ''
            && hash_equals($expected, (string) $request->query('hub_verify_token', ''));
        if ($ok) {
            return response((string) $request->query('hub_challenge', ''), 200);
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request): Response
    {
        $secret = \App\Services\MetaGraphService::decrypt($this->settings->get('meta.app_secret_enc')) ?? '';
        if ($secret !== '') {
            $signature = (string) $request->header('X-Hub-Signature-256', '');
            $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
            if (! hash_equals($expected, $signature)) {
                return response('Bad signature', 403);
            }
        }

        $payload = $request->json()->all();
        $object = (string) ($payload['object'] ?? '');
        try {
            if ($object === 'whatsapp_business_account') {
                $this->handleWhatsApp($payload);
            } elseif ($object === 'page' || $object === 'instagram') {
                $channel = $object === 'instagram' ? 'instagram' : 'facebook';
                foreach ((array) ($payload['entry'] ?? []) as $entry) {
                    foreach ((array) ($entry['messaging'] ?? []) as $event) {
                        $this->handleMessagingEvent($channel, $event);
                    }
                    foreach ((array) ($entry['changes'] ?? []) as $change) {
                        if (($change['field'] ?? '') === 'feed') {
                            $this->handleFeedChange((array) ($change['value'] ?? []));
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Meta webhook processing failed', ['error' => $e->getMessage()]);
        }

        return response('EVENT_RECEIVED', 200);
    }

    private function businessId(): int
    {
        $biz = (int) $this->settings->get('channels.business_id', 0);
        if ($biz > 0 && Business::whereKey($biz)->exists()) {
            return $biz;
        }

        return (int) (Business::orderBy('id')->value('id') ?? 1);
    }

    private function handleMessagingEvent(string $channel, array $event): void
    {
        $message = (array) ($event['message'] ?? []);
        $mid = (string) ($message['mid'] ?? '');
        if ($mid !== '') {
            // Meta redelivers an event when the first delivery is slow;
            // two copies can be in flight at once and both pass the
            // stored-mid check below before either commits. One delivery
            // per message id, enforced with a lock keyed on the mid.
            $lock = \Illuminate\Support\Facades\Cache::store('file')->lock('meta-mid-' . sha1($mid), 60);
            if (! $lock->acquire()) {
                return; // the twin delivery is processing this message now
            }
            try {
                $this->handleMessagingEventLocked($channel, $event, $message, $mid);
            } finally {
                $lock->release();
            }

            return;
        }
        $this->handleMessagingEventLocked($channel, $event, $message, $mid);
    }

    private function handleMessagingEventLocked(string $channel, array $event, array $message, string $mid): void
    {
        if (! empty($message['is_echo'])) {
            $this->handleEcho($channel, $event, $message, $mid);

            return;
        }
        $senderId = (string) ($event['sender']['id'] ?? '');
        if ($senderId === '') {
            return; // deliveries, reads, reactions — nothing to store
        }
        $biz = $this->businessId();
        if ($mid !== '' && ChatMessage::where('business_id', $biz)->where('external_id', $mid)->exists()) {
            return; // Meta redelivered this event — it was processed once already
        }
        $text = trim((string) ($message['text'] ?? ''));
        // A quick-reply tap carries a payload — translate ours into the
        // words the brain already understands (the tap also echoes the
        // button title as text, but the payload is the certain signal).
        $qrPayload = (string) ($message['quick_reply']['payload'] ?? '');
        if ($qrPayload === 'IMG_CONFIRM_YES') {
            $text = 'হ্যাঁ';
        } elseif ($qrPayload === 'IMG_CONFIRM_NO') {
            $text = 'না';
        }
        $attachment = null;
        $first = (array) (((array) ($message['attachments'] ?? []))[0] ?? []);
        if (! empty($first)) {
            $attachment = [
                'type' => (string) ($first['type'] ?? 'file'),
                'path' => null,
                'meta' => array_filter([
                    'url' => (string) ($first['payload']['url'] ?? ''),
                    'sticker_id' => $first['payload']['sticker_id'] ?? null,
                ]),
            ];
        }
        // A sticker (the big Like is the common one) is a reaction, not a
        // photo: never send it to the image pipeline. Give it the text form
        // the brain's reaction rule understands.
        $stickerId = $message['sticker_id'] ?? ($attachment['meta']['sticker_id'] ?? null);
        if ($stickerId !== null && $attachment !== null) {
            $attachment['type'] = 'sticker';
            if ($text === '') {
                $text = '👍';
            }
        }
        if ($text === '' && $attachment === null) {
            return;
        }
        $conv = Conversation::firstOrCreate(
            ['business_id' => $biz, 'channel' => $channel, 'external_id' => $senderId],
            ['customer_name' => $this->graph->senderName($channel, $senderId) ?? 'Messenger user'],
        );
        // An ad/post referral can name the product outright (ref=product:ID).
        $referral = $event['referral'] ?? ($event['postback']['referral'] ?? null);
        if (is_array($referral) && !$conv->product_id && preg_match('/product[:=](\d+)/', (string) ($referral['ref'] ?? ''), $mm)) {
            $refProduct = \App\Models\Product::where('business_id', $biz)->find((int) $mm[1]);
            if ($refProduct) {
                $conv->product_id = $refProduct->id;
                $conv->focus_at = now();
                $conv->save();
            }
        }
        $opts = [
            'external_id' => $mid !== '' ? $mid : null,
            'attachment' => $attachment,
            'referral' => $referral,
        ];
        if ($this->debounceSeconds() > 0) {
            // Store now; the whole burst is answered once, after a quiet window.
            $storedMsg = $this->brain->storeIncoming($conv, $text, $opts);
            if (($attachment['type'] ?? null) === 'image') {
                \App\Jobs\ProcessMediaMessageJob::dispatch($storedMsg->id);
            }
            if (in_array($attachment['type'] ?? null, ['audio', 'voice'], true)) {
                \App\Jobs\ProcessVoiceMessageJob::dispatch($storedMsg->id);
            }
            \App\Jobs\ProcessAiReplyJob::dispatch($conv->id)->delay(now()->addSeconds($this->debounceSeconds()));

            return;
        }
        $reply = $this->brain->handleIncoming($conv, $text, $opts);
        if ($reply) {
            $this->graph->dispatch($reply);
        }
        if (in_array($attachment['type'] ?? null, ['image', 'audio', 'voice'], true) && $mid !== '') {
            $stored = ChatMessage::where('business_id', $biz)->where('external_id', $mid)->first();
            if ($stored) {
                if (($attachment['type'] ?? null) === 'image') {
                    \App\Jobs\ProcessMediaMessageJob::dispatch($stored->id);
                } else {
                    \App\Jobs\ProcessVoiceMessageJob::dispatch($stored->id);
                }
            }
        }
    }

    private function debounceSeconds(): int
    {
        $behavior = (array) $this->settings->get('ai.behavior', []);

        return max(0, (int) ($behavior['debounce_seconds'] ?? 0));
    }

    /**
     * An echo is a message the Page sent. If it is one of ours (sent by this
     * app, or a message id we recorded when sending) it is ignored. Anything
     * else is a human replying from the Facebook app / Business Suite:
     * record it as a staff message and pause the AI on that conversation,
     * so a human and the AI never answer the same customer at once.
     */
    private function handleEcho(string $channel, array $event, array $message, string $mid): void
    {
        $biz = $this->businessId();
        $appId = (string) ($message['app_id'] ?? '');
        $ourApp = (string) $this->settings->get('meta.app_id', '');
        if ($appId !== '' && $ourApp !== '' && $appId === $ourApp) {
            return;
        }
        if ($mid !== '' && ChatMessage::where('business_id', $biz)->where('external_id', $mid)->exists()) {
            return; // our own recorded send coming back
        }
        $customerId = (string) ($event['recipient']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));
        if ($customerId === '' || $text === '') {
            return;
        }
        $conv = Conversation::where('business_id', $biz)->where('channel', $channel)->where('external_id', $customerId)->first();
        if (! $conv) {
            return;
        }
        ChatMessage::create([
            'business_id' => $biz,
            'conversation_id' => $conv->id,
            'direction' => 'out',
            'sender' => 'staff',
            'body' => $text,
            'status' => 'sent',
            'external_id' => $mid !== '' ? $mid : null,
        ]);
        $conv->ai_paused = true;
        $conv->last_message_at = now();
        $conv->save();
        AiActivity::create([
            'business_id' => $biz,
            'action' => 'human_takeover',
            'detail' => $conv->customer_name . ' — staff replied from Facebook; AI paused',
            'conversation_id' => $conv->id,
            'source' => 'echo',
        ]);
    }

    private function handleWhatsApp(array $payload): void
    {
        $biz = $this->businessId();
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $names = [];
                foreach ((array) ($value['contacts'] ?? []) as $contact) {
                    $names[(string) ($contact['wa_id'] ?? '')] = (string) ($contact['profile']['name'] ?? '');
                }
                foreach ((array) ($value['messages'] ?? []) as $msg) {
                    $from = (string) ($msg['from'] ?? '');
                    if ($from === '') {
                        continue;
                    }
                    $waId = (string) ($msg['id'] ?? '');
                    if ($waId !== '' && ChatMessage::where('business_id', $biz)->where('external_id', $waId)->exists()) {
                        continue; // redelivery — processed once already
                    }
                    $type = (string) ($msg['type'] ?? 'text');
                    $text = '';
                    $attachment = null;
                    if ($type === 'text') {
                        $text = trim((string) ($msg['text']['body'] ?? ''));
                    } elseif (in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
                        $media = (array) ($msg[$type] ?? []);
                        $text = trim((string) ($media['caption'] ?? ''));
                        $attachment = [
                            'type' => $type,
                            'path' => null,
                            'meta' => array_filter(['media_id' => (string) ($media['id'] ?? ''), 'mime' => (string) ($media['mime_type'] ?? '')]),
                        ];
                    } else {
                        continue;
                    }
                    if ($text === '' && $attachment === null) {
                        continue;
                    }
                    $conv = Conversation::firstOrCreate(
                        ['business_id' => $biz, 'channel' => 'whatsapp', 'external_id' => $from],
                        ['customer_name' => $names[$from] ?: 'WhatsApp user', 'customer_phone' => '+' . $from],
                    );
                    if (! $conv->customer_phone) {
                        $conv->update(['customer_phone' => '+' . $from]);
                    }
                    $waOpts = [
                        'external_id' => $waId !== '' ? $waId : null,
                        'attachment' => $attachment,
                    ];
                    if ($this->debounceSeconds() > 0) {
                        $storedMsg = $this->brain->storeIncoming($conv, $text, $waOpts);
                        if (($attachment['type'] ?? null) === 'image') {
                            \App\Jobs\ProcessMediaMessageJob::dispatch($storedMsg->id);
                        }
                        if (in_array($attachment['type'] ?? null, ['audio', 'voice'], true)) {
                            \App\Jobs\ProcessVoiceMessageJob::dispatch($storedMsg->id);
                        }
                        \App\Jobs\ProcessAiReplyJob::dispatch($conv->id)->delay(now()->addSeconds($this->debounceSeconds()));
                        continue;
                    }
                    $reply = $this->brain->handleIncoming($conv, $text, $waOpts);
                    if ($reply) {
                        $this->graph->dispatch($reply);
                    }
                    if (in_array($attachment['type'] ?? null, ['image', 'audio', 'voice'], true) && $waId !== '') {
                        $stored = ChatMessage::where('business_id', $biz)->where('external_id', $waId)->first();
                        if ($stored) {
                            if (($attachment['type'] ?? null) === 'image') {
                                \App\Jobs\ProcessMediaMessageJob::dispatch($stored->id);
                            } else {
                                \App\Jobs\ProcessVoiceMessageJob::dispatch($stored->id);
                            }
                        }
                    }
                }
            }
        }
    }

    private function handleFeedChange(array $value): void
    {
        if (($value['item'] ?? '') !== 'comment' || ($value['verb'] ?? '') !== 'add') {
            return;
        }
        $commentId = (string) ($value['comment_id'] ?? '');
        if ($commentId === '') {
            return;
        }
        $biz = $this->businessId();
        if (Comment::where('business_id', $biz)->where('external_id', $commentId)->exists()) {
            return; // duplicate delivery
        }
        $comment = Comment::create([
            'business_id' => $biz,
            'channel' => 'facebook',
            'post_label' => 'Facebook post ' . substr((string) ($value['post_id'] ?? ''), -6),
            'post_ref' => (string) ($value['post_id'] ?? ''),
            'external_id' => $commentId,
            'author_name' => (string) ($value['from']['name'] ?? 'Facebook user'),
            'author_ref' => (string) ($value['from']['id'] ?? ''),
            'body' => (string) ($value['message'] ?? ''),
            'status' => 'awaiting',
        ]);
        $this->brain->handleComment($comment);
        $comment->refresh();
        foreach ($comment->replies()->where('status', 'sent')->get() as $replyRow) {
            if ($replyRow->kind === 'private') {
                $this->graph->privateReplyToComment($comment, (string) $replyRow->body);
            } else {
                $this->graph->replyToComment($comment, (string) $replyRow->body);
            }
        }
        if ($comment->status === 'hidden') {
            $this->graph->setCommentHidden($comment, true);
        }
    }
}
