<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\MetaGraphService;
use App\Services\RuleBrainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Answers a stored burst of customer messages once the quiet window has
 * passed (debounce). Dispatched by the webhook with a delay; the
 * ai:process-pending sweep is the safety net if a job is ever lost.
 * Unique per conversation: a burst dispatches one job per message, but
 * only one of them may sit in the queue — the rest would find the burst
 * already answered (RuleBrainService::processPending also locks).
 */
class ProcessAiReplyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 180;

    public function __construct(public int $conversationId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->conversationId;
    }

    public function handle(RuleBrainService $brain, MetaGraphService $graph): void
    {
        $conv = Conversation::find($this->conversationId);
        if (!$conv) {
            return;
        }
        $reply = $brain->processPending($conv);
        if ($reply) {
            $graph->dispatch($reply);
        }
    }
}
