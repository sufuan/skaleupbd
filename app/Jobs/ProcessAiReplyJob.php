<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\MetaGraphService;
use App\Services\RuleBrainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Answers a stored burst of customer messages once the quiet window has
 * passed (debounce). Dispatched by the webhook with a delay; the
 * ai:process-pending sweep is the safety net if a job is ever lost.
 */
class ProcessAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $conversationId)
    {
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
