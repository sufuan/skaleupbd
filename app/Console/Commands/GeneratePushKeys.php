<?php

namespace App\Console\Commands;

use App\Services\MetaGraphService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Generates the platform VAPID keypair for browser Web Push and stores
 * it in Settings (platform scope). The private key is stored encrypted
 * (MetaGraphService::encrypt, the same helper as meta.app_secret_enc).
 * Idempotent: existing keys are never overwritten. Values are never
 * printed - only whether they were set.
 */
class GeneratePushKeys extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Generate and store the platform VAPID keys for browser Web Push (once only)';

    public function handle(SettingsService $settings): int
    {
        if ($settings->get('push.vapid_public') && $settings->get('push.vapid_private')) {
            $this->line('VAPID keys: already set');

            return self::SUCCESS;
        }

        $keys = VAPID::createVapidKeys();
        $settings->setLogged('push.vapid_public', $keys['publicKey'], null);
        $settings->setLogged('push.vapid_private', MetaGraphService::encrypt($keys['privateKey']), null);
        $this->line('VAPID keys: set');

        return self::SUCCESS;
    }
}
