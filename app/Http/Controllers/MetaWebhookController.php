<?php

namespace App\Http\Controllers;

use App\Models\Business;
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
        if (! empty($message['is_echo'])) {
            return; // our own sends coming back
        }
        $text = trim((string) ($message['text'] ?? ''));
        $senderId = (string) ($event['sender']['id'] ?? '');
        if ($text === '' || $senderId === '') {
            return; // deliveries, reads, reactions, media-only — not handled yet
        }
        $biz = $this->businessId();
        $conv = Conversation::firstOrCreate(
            ['business_id' => $biz, 'channel' => $channel, 'external_id' => $senderId],
            ['customer_name' => $this->graph->senderName($channel, $senderId) ?? 'Messenger user'],
        );
        $reply = $this->brain->handleIncoming($conv, $text);
        if ($reply) {
            $this->graph->dispatch($reply);
        }
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
                    if (($msg['type'] ?? '') !== 'text') {
                        continue;
                    }
                    $from = (string) ($msg['from'] ?? '');
                    $text = trim((string) ($msg['text']['body'] ?? ''));
                    if ($from === '' || $text === '') {
                        continue;
                    }
                    $conv = Conversation::firstOrCreate(
                        ['business_id' => $biz, 'channel' => 'whatsapp', 'external_id' => $from],
                        ['customer_name' => $names[$from] ?: 'WhatsApp user', 'customer_phone' => '+' . $from],
                    );
                    if (! $conv->customer_phone) {
                        $conv->update(['customer_phone' => '+' . $from]);
                    }
                    $reply = $this->brain->handleIncoming($conv, $text);
                    if ($reply) {
                        $this->graph->dispatch($reply);
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
