<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Nishchit (nishchit.tech) — the default provider
    |--------------------------------------------------------------------------
    | Fully documented in public: docs at nishchit.tech/docs (quickstart,
    | send-an-sms, webhooks) and the OpenAPI spec at nishchit.tech/openapi.json.
    | Send: POST /v1/messages with Authorization: Bearer <nk_test_…|nk_live_…>
    | and JSON {to (E.164), body}; balance: GET /v1/balance (taka in `bdt`).
    | Delivery events arrive signed at POST /api/webhooks/sms/nishchit
    | (HMAC-SHA256, see SmsWebhookController). Sellers' own API keys, sender
    | IDs and webhook secrets live per business in the settings table
    | (marketing.{business_id}.sms_*); only the provider's API shape is here.
    */
    'nishchit' => [
        'base_url' => env('NISHCHIT_BASE_URL', 'https://api.nishchit.tech'),
        'send_path' => env('NISHCHIT_SEND_PATH', '/v1/messages'),
        'balance_path' => env('NISHCHIT_BALANCE_PATH', '/v1/balance'),
        'auth' => 'bearer', // Authorization: Bearer <key>
    ],

    /*
    |--------------------------------------------------------------------------
    | Amar SMS (portal.amarsms.com)
    |--------------------------------------------------------------------------
    | Fill send_path / balance_path / auth from portal.amarsms.com/docs after
    | the Amar SMS account is approved. While send_path is empty the driver
    | stays inert and reports 'Gateway not configured' — no endpoint is
    | guessed. Sellers' own API keys and sender IDs live per business in the
    | settings table (marketing.{business_id}.sms_*); only the provider's API
    | shape lives here.
    */
    'amarsms' => [
        'base_url' => env('AMARSMS_BASE_URL', 'https://portal.amarsms.com'),
        'send_path' => env('AMARSMS_SEND_PATH', ''),
        'balance_path' => env('AMARSMS_BALANCE_PATH', ''),
        'auth' => env('AMARSMS_AUTH', 'bearer'), // bearer = Authorization: Bearer <key> | header = X-API-Key: <key>
    ],
];
