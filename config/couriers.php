<?php

return [
    // Test mode: when true, every API courier uses the mock driver (fixtures),
    // so the full delivery flow can be exercised without live seller accounts.
    // Never enable this on a live server.
    'mock' => env('COURIER_MOCK', false),
];
