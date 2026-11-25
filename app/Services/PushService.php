<?php

namespace App\Services;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Browser Web Push sending (owner order 2026-11-25: Web Push ONLY,
 * nothing else). Reads the platform VAPID keypair from Settings
 * (push.vapid_public / push.vapid_private, the private key stored
 * encrypted exactly like meta.app_secret_enc). When the keys are not
 * set the whole service is a silent no-op, so the app behaves exactly
 * as before until the platform runs `php artisan push:keys`.
 */
class PushService
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function publicKey(): ?string
    {
        $key = $this->settings->get('push.vapid_public');

        return $key ? (string) $key : null;
    }

    public function sendToUser(int $userId, string $title, string $body, string $url): void
    {
        $public = $this->publicKey();
        $private = MetaGraphService::decrypt($this->settings->get('push.vapid_private'));
        if (! $public || ! $private) {
            return; // not configured yet: silent no-op
        }

        $subs = PushSubscription::where('user_id', $userId)->get();
        if ($subs->isEmpty()) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string) config('app.url'),
                'publicKey' => $public,
                'privateKey' => $private,
            ],
        ]);
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'icon' => '/favicon.ico',
        ]);

        foreach ($subs as $sub) {
            try {
                $report = $webPush->sendOneNotification(Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                    'contentEncoding' => 'aes128gcm',
                ]), $payload);
            } catch (\Throwable) {
                continue; // one bad browser must not stop the others
            }
            // The push service says this subscription is gone for good
            // (browser removed, permission revoked): drop the row so
            // we stop paying attempts on it.
            $status = $report->getResponse()?->getStatusCode();
            if ($report->isSubscriptionExpired() || in_array($status, [404, 410], true)) {
                $sub->delete();
            }
        }
    }
}
