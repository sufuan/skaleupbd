<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('ai:process-pending')->everyMinute();
Schedule::command('workinbox:run')->everyFiveMinutes();
Schedule::command('pricing:expire-cycles')->daily();
Schedule::command('storefront:lapse-sweep')->daily();

Artisan::command('storefront:lapse-sweep', function () {
    $stats = app(\App\Services\StorefrontService::class)->lapseSweep();
    $this->comment('Storefront lapse sweep: '.json_encode($stats));
})->purpose('Unpublish storefronts whose points pack ended beyond the grace window');

Artisan::command('pricing:expire-cycles', function () {
    $stats = app(\App\Services\TokenService::class)->expireCycles();
    $this->comment('Pricing cycles: '.json_encode($stats));
})->purpose('Expire M19 monthly point buckets and yearly wallets whose time has run out');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('marketing:run', function () {
    $stats = app(\App\Services\AutomationService::class)->runDue();
    $this->comment('Marketing automation: '.json_encode($stats));
})->purpose('Run due marketing automation SMS (reminders, win-back, post-return, back-in-stock)');

Artisan::command('workinbox:run', function () {
    $stats = app(\App\Services\WorkInboxService::class)->evaluateAll();
    $this->comment('Work Inbox: '.json_encode($stats));
})->purpose('Run the Work Inbox rules: create problem tasks, auto-close resolved ones, escalate overdue ones');

Artisan::command('ai:process-pending', function () {
    $brain = app(\App\Services\RuleBrainService::class);
    $graph = app(\App\Services\MetaGraphService::class);
    $ids = \App\Models\ChatMessage::where('direction', 'in')
        ->where('created_at', '>=', now()->subDay())
        ->distinct()
        ->pluck('conversation_id');
    $answered = 0;
    foreach ($ids as $cid) {
        $conv = \App\Models\Conversation::find($cid);
        if (!$conv) {
            continue;
        }
        $reply = $brain->processPending($conv);
        if ($reply) {
            $graph->dispatch($reply);
            $answered++;
        }
    }
    $this->comment('AI pending sweep: '.$answered.' conversation(s) answered');
})->purpose('Answer stored customer message bursts after their quiet window (debounce safety net)');
