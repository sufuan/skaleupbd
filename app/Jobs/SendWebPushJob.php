<?php

namespace App\Jobs;

use App\Services\PushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one browser Web Push to one user (see PushService). Dispatched
 * by the two owner-chosen triggers only: a conversation flipping to
 * needs_human, and a Work Inbox task created under a rule whose push
 * toggle is on.
 */
class SendWebPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public string $url,
    ) {
    }

    public function handle(PushService $push): void
    {
        $push->sendToUser($this->userId, $this->title, $this->body, $this->url);
    }
}
