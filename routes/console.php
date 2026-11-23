<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('ai:process-pending')->everyMinute();
Schedule::command('workinbox:run')->everyFiveMinutes();
Schedule::command('pricing:expire-cycles')->daily();
Schedule::command('storefront:lapse-sweep')->daily();
Schedule::command('growth:rollup')->everyFifteenMinutes();
Schedule::command('growth:tasks')->hourly();
Schedule::command('growth:reconcile')->daily();
// Website integrations: retry outbound webhook deliveries, process
// parked WooCommerce inbound events, reconcile WooCommerce state.
// (The zip's integrations:prune-webhook-log line is intentionally not
// scheduled: that command class does not exist in the kit.)
Schedule::command('integrations:sweep-webhooks')->everyMinute()->withoutOverlapping();
Schedule::command('integrations:sweep-woo-inbound')->everyMinute()->withoutOverlapping();
Schedule::command('integrations:reconcile-woo')->everyFifteenMinutes()->withoutOverlapping();

Artisan::command('growth:rollup', function () {
    $svc = app(\App\Services\InsightService::class);
    $ids = \App\Models\ConversationStage::distinct()->pluck('business_id');
    foreach ($ids as $biz) {
        $svc->rollup((int) $biz);
    }
    $this->comment('Sales Growth rollups: '.count($ids).' business(es)');
})->purpose('Recompute Sales Growth daily rollups (last 35 days) from the stage layer');

Artisan::command('growth:tasks', function () {
    $svc = app(\App\Services\InsightService::class);
    $ids = \App\Models\ConversationStage::distinct()->pluck('business_id');
    foreach ($ids as $biz) {
        $svc->generateTasks((int) $biz);
    }
    $this->comment('Sales Growth tasks: '.count($ids).' business(es)');
})->purpose('Generate today\'s Sales Growth action tasks (complaint, ready, interested)');

Artisan::command('growth:reconcile', function () {
    $svc = app(\App\Services\InsightService::class);
    $ids = \App\Models\ConversationStage::distinct()->pluck('business_id');
    $bad = 0;
    foreach ($ids as $biz) {
        if (! $svc->reconcile((int) $biz)['ok']) {
            $bad++;
        }
    }
    $this->comment('Sales Growth reconciliation: '.count($ids).' checked, '.$bad.' failed');
})->purpose('Independently re-derive Sales Growth counts from messages + orders and hide any that do not reconcile');

Artisan::command('growth:backfill {--business=}', function () {
    $svc = app(\App\Services\InsightService::class);
    $ids = $this->option('business')
        ? [(int) $this->option('business')]
        : \App\Models\Business::pluck('id')->all();
    foreach ($ids as $biz) {
        $stats = $svc->backfill((int) $biz);
        $this->comment('Backfill business '.$biz.': '.json_encode($stats));
    }
})->purpose('Derive Sales Growth stages for conversations that predate the stage layer (idempotent)');

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
