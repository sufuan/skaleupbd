<?php

/**
 * SkaleUp BD - Website Integration Kit: Plain PHP Snippet
 * ========================================================
 * Place in: docs/integration-kit/plain-php-snippet.php
 *
 * For sellers whose website runs plain PHP (no framework).
 * Follow the numbered steps. This code goes on YOUR OWN site, not SkaleUp.
 *
 * Requirement: PHP 7.4+ with curl extension enabled.
 */

// =============================================================================
// STEP 1 — Configuration (put this in a config file or .env on YOUR site)
// =============================================================================

define('SKALEUP_ENDPOINT',       'https://scalenowbd.com/api/external/v1/orders');
define('SKALEUP_API_KEY',        'your_full_api_key_here');       // from SkaleUp dashboard
define('SKALEUP_BUSINESS_ID',    'your_business_id_here');        // from SkaleUp dashboard
define('SKALEUP_WEBHOOK_SECRET', 'your_webhook_secret_here');     // from SkaleUp dashboard


// =============================================================================
// STEP 2 — Function to send an order to SkaleUp
//          IMPORTANT: Call this from a background queue / cron job on YOUR site,
//          NEVER directly inside the checkout page request.
// =============================================================================

/**
 * Send a single order to the SkaleUp dashboard.
 *
 * @param  array   $order        Your order data (see format below).
 * @param  string  $idempotencyKey  Use your order ID so duplicates are ignored.
 * @return array{success: bool, skaleupOrderId: string|null, error: string|null}
 */
function sendOrderToSkaleUp(array $order, string $idempotencyKey): array
{
    $body = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . SKALEUP_API_KEY,
        'x-business-id: ' . SKALEUP_BUSINESS_ID,
        'x-idempotency-key: ' . $idempotencyKey,
    ];

    $ch = curl_init(SKALEUP_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['success' => false, 'skaleupOrderId' => null, 'error' => $err];
    }

    $json = json_decode($raw, true);

    if ($code >= 200 && $code < 300 && ($json['success'] ?? false)) {
        return [
            'success'       => true,
            'skaleupOrderId' => $json['data']['orderId'] ?? null,
            'error'         => null,
        ];
    }

    return [
        'success'       => false,
        'skaleupOrderId' => null,
        'error'         => ($json['message'] ?? $raw),
    ];
}


// =============================================================================
// STEP 3 — Example order format
// =============================================================================

/*
$orderData = [
    'externalRef'     => '1234',                  // YOUR order ID (string)
    'customerName'    => 'Rahim Uddin',
    'customerNumber'  => '01712345678',           // BD phone, 11 digits
    'customerAddress' => '12 Mirpur Road, Dhaka',
    'district'        => 'Dhaka',
    'deliveryCharge'  => 60.00,
    'discount'        => 0.00,
    'advance'         => 0.00,
    'note'            => 'Handle with care',
    'webStatus'       => 'pending',
    'products'        => [
        [
            'productId' => '55',                  // YOUR product ID
            'name'      => 'Cotton T-Shirt',
            'quantity'  => 2,
            'price'     => 450.00,
        ],
    ],
];

$result = sendOrderToSkaleUp($orderData, $orderData['externalRef']);

if ($result['success']) {
    // Store $result['skaleupOrderId'] in your database if you want.
} else {
    // Log the error and retry later via your queue / cron.
    error_log('SkaleUp error: ' . $result['error']);
}
*/


// =============================================================================
// STEP 4 — Receive status updates FROM SkaleUp (inbound webhook)
//          Create a new PHP file on your server, e.g. /webhook-skaleup.php
//          Register the URL in SkaleUp Dashboard > Website Integration > Webhook URL
// =============================================================================

/*
// /webhook-skaleup.php  (on YOUR server)

$secret    = 'your_webhook_secret_here';
$timestamp = $_SERVER['HTTP_X_SKALE_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_SKALE_SIGNATURE'] ?? '';
$body      = file_get_contents('php://input');

// Reject requests older than 5 minutes.
if (abs(time() - (int)$timestamp) > 300) {
    http_response_code(400);
    echo 'Timestamp expired';
    exit;
}

$expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    echo 'Bad signature';
    exit;
}

$payload = json_decode($body, true);
$event   = $payload['event'] ?? '';

switch ($event) {
    case 'order.shipped':
        // $payload['externalRef'] = your order ID
        // $payload['courier']     = courier name
        // $payload['tracking']    = tracking number
        // Update your order in the database here.
        break;

    case 'order.delivered':
        // Mark your order as delivered.
        break;

    case 'order.cancelled':
        // Mark your order as cancelled.
        break;

    case 'stock.updated':
        // $payload['productId'] = your product ID
        // $payload['available'] = new available stock
        break;
}

http_response_code(200);
echo 'OK';
*/
