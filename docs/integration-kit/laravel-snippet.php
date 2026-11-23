<?php

/**
 * SkaleUp BD - Website Integration Kit: Laravel Snippet
 * ======================================================
 * Place in: docs/integration-kit/laravel-snippet.php
 *
 * This file is a COPY-PASTE GUIDE for a seller whose website runs Laravel.
 * Follow the numbered steps. Do NOT edit the SkaleUp dashboard side — only
 * copy the parts that go in YOUR OWN website's codebase.
 *
 * Requirements: Laravel 9+, GuzzleHTTP (included with Laravel by default).
 */

// =============================================================================
// STEP 1 — Store your SkaleUp credentials in .env on YOUR site
// =============================================================================
//
// SKALEUP_ENDPOINT=https://scalenowbd.com/api/external/v1/orders
// SKALEUP_API_KEY=your_full_api_key_here
// SKALEUP_BUSINESS_ID=your_business_id_here
// SKALEUP_WEBHOOK_SECRET=your_webhook_secret_here
//
// Get the values from: SkaleUp Dashboard > Website Integration > Connect Website


// =============================================================================
// STEP 2 — Create the queued job that sends the order
// =============================================================================
// Save as: app/Jobs/SendOrderToSkaleUp.php  (on YOUR website)

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendOrderToSkaleUp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 1800, 7200, 43200]; // 1m 5m 30m 2h 12h

    public function __construct(private array $orderData) {}

    public function handle(): void
    {
        $response = Http::withHeaders([
            'x-api-key'         => config('services.skaleup.api_key'),
            'x-business-id'     => config('services.skaleup.business_id'),
            'x-idempotency-key' => $this->orderData['externalRef'],
            'Content-Type'      => 'application/json',
            'Accept'            => 'application/json',
        ])->post(config('services.skaleup.endpoint'), $this->orderData);

        if ($response->failed()) {
            // SkaleUp returned an error — retry via backoff.
            throw new \RuntimeException(
                'SkaleUp rejected order: ' . $response->status() . ' ' . $response->body()
            );
        }

        Log::info('SkaleUp order sent', [
            'external_ref' => $this->orderData['externalRef'],
            'skaleup_id'   => $response->json('data.orderId'),
        ]);
    }
}


// =============================================================================
// STEP 3 — Add SkaleUp credentials to config/services.php on YOUR site
// =============================================================================
//
//  'skaleup' => [
//      'endpoint'    => env('SKALEUP_ENDPOINT', 'https://scalenowbd.com/api/external/v1/orders'),
//      'api_key'     => env('SKALEUP_API_KEY'),
//      'business_id' => env('SKALEUP_BUSINESS_ID'),
//      'webhook_secret' => env('SKALEUP_WEBHOOK_SECRET'),
//  ],


// =============================================================================
// STEP 4 — Dispatch the job AFTER your order is saved to YOUR database
//          NEVER call this directly in the checkout controller; use a job.
// =============================================================================
//
// In your OrderObserver or Order::created event on YOUR site:
//
//  \App\Jobs\SendOrderToSkaleUp::dispatch([
//      'externalRef'     => (string) $order->id,          // your order ID as string
//      'customerName'    => $order->customer_name,
//      'customerNumber'  => $order->phone,                // 01XXXXXXXXX format
//      'customerAddress' => $order->address,
//      'district'        => $order->district,
//      'deliveryCharge'  => (float) $order->delivery_charge,
//      'discount'        => (float) $order->discount,
//      'advance'         => (float) $order->advance,
//      'note'            => $order->note ?? '',
//      'webStatus'       => 'pending',
//      'products'        => $order->items->map(fn($item) => [
//          'productId' => (string) $item->product_id,    // your product ID
//          'name'      => $item->product_name,
//          'quantity'  => $item->quantity,
//          'price'     => (float) $item->unit_price,
//      ])->toArray(),
//  ]);


// =============================================================================
// STEP 5 — Receive status updates FROM SkaleUp (inbound webhook)
// =============================================================================
// Save as: app/Http/Controllers/SkaleUpWebhookController.php  (on YOUR site)

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SkaleUpWebhookController extends Controller
{
    /**
     * Verify HMAC-SHA256 signature and handle the event.
     * Register route: POST /webhooks/skaleup  (exempt from CSRF on YOUR site).
     */
    public function handle(Request $request): Response
    {
        $timestamp = $request->header('X-Skale-Timestamp', '');
        $signature = $request->header('X-Skale-Signature', '');
        $body      = $request->getContent();

        // Reject stale requests (older than 5 minutes).
        if (abs(time() - (int) $timestamp) > 300) {
            return response('Timestamp expired', 400);
        }

        $expected = hash_hmac(
            'sha256',
            $timestamp . '.' . $body,
            config('services.skaleup.webhook_secret')
        );

        if (! hash_equals($expected, $signature)) {
            return response('Bad signature', 401);
        }

        $event   = $request->header('X-Skale-Event-Id', '');
        $payload = json_decode($body, true);
        $type    = $payload['event'] ?? '';

        // Handle each event type as needed for YOUR site.
        match ($type) {
            'order.shipped'   => $this->onShipped($payload),
            'order.delivered' => $this->onDelivered($payload),
            'order.cancelled' => $this->onCancelled($payload),
            'stock.updated'   => $this->onStockUpdated($payload),
            default           => null,
        };

        return response('OK', 200);
    }

    private function onShipped(array $payload): void
    {
        // $payload['externalRef'] = YOUR order ID
        // $payload['courier']     = courier name
        // $payload['tracking']    = tracking number
        // Update YOUR order with the tracking info here.
    }

    private function onDelivered(array $payload): void
    {
        // Mark YOUR order as delivered.
    }

    private function onCancelled(array $payload): void
    {
        // Mark YOUR order as cancelled.
    }

    private function onStockUpdated(array $payload): void
    {
        // $payload['productId'] = your product ID
        // $payload['available'] = new available quantity
        // Update YOUR product stock here if you display it.
    }
}
