<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('marketing:run', function () {
    $stats = app(\App\Services\AutomationService::class)->runDue();
    $this->comment('Marketing automation: '.json_encode($stats));
})->purpose('Run due marketing automation SMS (reminders, win-back, post-return, back-in-stock)');
