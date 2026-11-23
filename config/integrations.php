<?php

// Goes in: config/integrations.php (new file).

return [
    'webhook' => [
        // Wait after failed attempt 1, 2, 3, 4, 5. A sixth failure marks the event dead.
        'backoff_seconds' => [60, 300, 1800, 7200, 43200],
        'timeout' => 10,
        'connect_timeout' => 5,
        'max_response_bytes' => 262144,
        'degraded_after_failures' => 3,
        'disable_after_failures' => 15,       // consecutive failed attempts, checked when an event dies
        'paused_recheck_seconds' => 600,      // how often the sweeper looks at waiting events for a suspended shop
        'max_wait_days' => 7,                 // a paused event older than this is skipped instead of sent
        'max_waiting_events' => 2000,         // per connection; beyond this new events are not stored
        'shipped_tracking_grace_seconds' => 300,
        'sweep_overdue_minutes' => 15,
        'retention_days' => 30,
    ],

    // Where the "Top up points" button goes. Set INTEGRATIONS_TOP_UP_URL to the real Points & Billing path.
    'top_up_url' => env('INTEGRATIONS_TOP_UP_URL', '/billing'),
];
