<?php

namespace App\Jobs;

use App\Models\AiActivity;
use App\Models\ChatMessage;
use App\Models\Product;
use App\Services\ImageMatchService;
use App\Services\MetaGraphService;
use App\Services\RuleBrainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * A customer sent a photo. Download it (Meta CDN hosts only, size cap),
 * identify the product — caption, conversation focus, perceptual hash,
 * then vision over a shortlist — and act on the confidence code decides:
 * high = answer, medium = ask with the product name, low = a human.
 * If the caption flow already produced an AI reply, this job stays out.
 */
class ProcessMediaMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $messageId)
    {
    }

    public function handle(RuleBrainService $brain, MetaGraphService $graph, ImageMatchService $matcher): void
    {
        $msg = ChatMessage::find($this->messageId);
        if (!$msg || $msg->attachment_type !== 'image') {
            return;
        }
        $conv = $msg->conversation;
        if (!$conv) {
            return;
        }
        $answered = ChatMessage::where('conversation_id', $conv->id)
            ->where('direction', 'out')->where('sender', 'ai')->where('id', '>', $msg->id)->exists();
        if ($answered) {
            return;
        }
        // From here this message is handled by the media path — the burst
        // sweep must not answer its caption a second time.
        $meta = (array) ($msg->meta ?? []);
        $meta['processed'] = true;
        $msg->meta = $meta;
        $msg->save();
        $biz = (int) $msg->business_id;

        $path = $msg->attachment_path;
        if (!$path) {
            $path = $this->download($msg, $graph);
            if (!$path) {
                return; // link-only bubble stays for staff; nothing is guessed
            }
            $msg->attachment_path = $path;
            $msg->save();
        }

        $caption = trim((string) $msg->body);
        $product = null;
        $level = 'low';
        if ($caption !== '') {
            $product = $brain->productFor($biz, $caption, $conv);
            if ($product) {
                $level = 'high';
            }
        }
        if (!$product && $conv->product_id) {
            $product = Product::where('business_id', $biz)->find($conv->product_id);
            if ($product) {
                $level = 'high';
            }
        }
        if (!$product) {
            $r = $matcher->identify($biz, $path, $caption);
            if (($r['image_type'] ?? 'product') !== 'product') {
                // Payment proof, a person, a document — a human handles it.
                $meta = (array) ($msg->meta ?? []);
                $meta['image_type'] = $r['image_type'];
                $msg->meta = $meta;
                $msg->save();
                $conv->needs_human = true;
                $conv->save();
                AiActivity::create(['business_id' => $biz, 'conversation_id' => $conv->id, 'action' => 'image_flagged', 'detail' => $conv->customer_name . ' — photo type: ' . $r['image_type'], 'source' => 'image']);

                return;
            }
            $product = $r['product'];
            $level = $r['level'];
        }

        if (!$product || $level === 'low') {
            $conv->needs_human = true;
            $conv->save();
            AiActivity::create(['business_id' => $biz, 'conversation_id' => $conv->id, 'action' => 'image_no_match', 'detail' => $conv->customer_name . ' — photo could not be matched', 'source' => 'image']);

            return;
        }

        if ($level === 'medium') {
            $state = (array) ($conv->state_data ?? []);
            $state['image_confirm'] = ['product_id' => $product->id, 'message_id' => $msg->id];
            $conv->state_data = $state;
            $conv->save();
            if (!empty($product->image)) {
                $img = (string) $product->image;
                $graph->sendImage($conv, str_starts_with($img, 'http') ? $img : url('/storage/' . ltrim($img, '/')));
            }
            $reply = $brain->simpleReply($conv, 'এই প্রোডাক্টটাই কি আপনি দেখতে চাইছেন? — ' . $product->name . '। হ্যাঁ অথবা না বলুন।', 'image_confirm');
            if ($reply) {
                // One-tap answers where the channel supports them (Messenger /
                // Instagram quick replies); WhatsApp falls back to plain text.
                $graph->dispatchQuickReplies($reply, [
                    ['title' => 'হ্যাঁ, এটাই', 'payload' => 'IMG_CONFIRM_YES'],
                    ['title' => 'না, এটা না', 'payload' => 'IMG_CONFIRM_NO'],
                ]);
            }

            return;
        }

        // High confidence: learn the reference, focus the product, answer.
        $matcher->learn($biz, $product->id, $path, 'customer');
        $conv->product_id = $product->id;
        $conv->focus_at = now();
        $conv->save();
        $text = $caption !== '' ? $caption : 'এটার দাম কত? স্টকে আছে?';
        $reply = $brain->handleIncoming($conv, $text, ['prestored_ids' => [$msg->id]]);
        if ($reply) {
            $graph->dispatch($reply);
        }
    }

    /** Fetch the photo from Meta's CDN (allowlisted hosts), cap 8 MB, images only. */
    private function download(ChatMessage $msg, MetaGraphService $graph): ?string
    {
        $meta = (array) ($msg->attachment_meta ?? []);
        $url = (string) ($meta['url'] ?? '');
        $headers = [];
        $conv = $msg->conversation;
        if ($conv && $conv->channel === 'whatsapp' && !empty($meta['media_id'])) {
            $cfg = $graph->configForConversation($conv);
            if (empty($cfg['token'])) {
                return null;
            }
            try {
                $res = Http::timeout(15)->withToken($cfg['token'])->get('https://graph.facebook.com/v21.0/' . $meta['media_id']);
                $url = (string) ($res->json('url') ?? '');
            } catch (\Throwable) {
                return null;
            }
            if ($url === '') {
                return null;
            }
            $headers = ['Authorization' => 'Bearer ' . $cfg['token']];
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $ok = false;
        foreach (['fbcdn.net', 'cdninstagram.com', 'whatsapp.net'] as $suffix) {
            if ($host === $suffix || str_ends_with($host, $suffix)) {
                $ok = true;
                break;
            }
        }
        if (!$ok || $url === '') {
            return null;
        }
        try {
            $res = Http::timeout(20)->withHeaders($headers)->get($url);
            if (!$res->successful()) {
                return null;
            }
            $bytes = $res->body();
            $mime = (string) ($res->header('Content-Type') ?? '');
        } catch (\Throwable) {
            return null;
        }
        if (strlen($bytes) > 8 * 1024 * 1024 || !str_starts_with($mime, 'image/')) {
            return null;
        }
        $ext = str_contains($mime, 'png') ? 'png' : (str_contains($mime, 'webp') ? 'webp' : 'jpg');
        $rel = 'chat-media/' . $msg->business_id . '/' . $msg->id . '.' . $ext;
        Storage::disk('public')->put($rel, $bytes);

        return $rel;
    }
}
