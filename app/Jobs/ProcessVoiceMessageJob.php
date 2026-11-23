<?php

namespace App\Jobs;

use App\Models\AiActivity;
use App\Models\ChatMessage;
use App\Services\AiTalkerService;
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
 * A customer sent a voice note. Download it (Meta CDN hosts only),
 * transcribe it, and let the Rule Brain answer the words like any
 * other message. The transcript is kept on the message for staff.
 * Anything that cannot be heard goes to a human — never a guess.
 */
class ProcessVoiceMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $messageId)
    {
    }

    public function handle(RuleBrainService $brain, MetaGraphService $graph, AiTalkerService $talker): void
    {
        $msg = ChatMessage::find($this->messageId);
        if (!$msg || !in_array($msg->attachment_type, ['audio', 'voice'], true)) {
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
        $biz = (int) $msg->business_id;
        $meta = (array) ($msg->meta ?? []);
        $meta['processed'] = true;
        $msg->meta = $meta;
        $msg->save();

        $path = $msg->attachment_path;
        if (!$path) {
            $path = $this->download($msg, $graph);
            if (!$path) {
                $this->toHuman($biz, $conv->id, $conv->customer_name, 'voice note could not be downloaded');
                return;
            }
            $msg->attachment_path = $path;
            $msg->save();
        }
        $abs = Storage::disk('public')->path($path);
        $bytes = is_file($abs) ? (string) file_get_contents($abs) : '';
        $mime = $bytes !== '' ? (mime_content_type($abs) ?: 'audio/ogg') : 'audio/ogg';
        $transcript = $bytes !== '' ? $talker->transcribe($biz, $bytes, $mime) : null;
        if ($transcript === null || trim($transcript) === '') {
            $this->toHuman($biz, $conv->id, $conv->customer_name, 'voice note could not be transcribed');
            return;
        }
        $meta['transcript'] = $transcript;
        $msg->meta = $meta;
        $msg->save();

        $reply = $brain->handleIncoming($conv, $transcript, ['prestored_ids' => [$msg->id]]);
        if ($reply) {
            $graph->dispatch($reply);
        }
    }

    private function toHuman(int $biz, int $conversationId, ?string $name, string $why): void
    {
        $conv = \App\Models\Conversation::find($conversationId);
        if ($conv) {
            $conv->needs_human = true;
            $conv->save();
        }
        AiActivity::create(['business_id' => $biz, 'conversation_id' => $conversationId, 'action' => 'voice_to_human', 'detail' => ($name ?? 'Customer') . ' — ' . $why, 'source' => 'voice']);
    }

    /** Fetch the audio from Meta's CDN (allowlisted hosts), cap 16 MB. */
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
        } catch (\Throwable) {
            return null;
        }
        if (strlen($bytes) > 16 * 1024 * 1024) {
            return null;
        }
        $rel = 'chat-media/' . $msg->business_id . '/' . $msg->id . '.ogg';
        Storage::disk('public')->put($rel, $bytes);

        return $rel;
    }
}
