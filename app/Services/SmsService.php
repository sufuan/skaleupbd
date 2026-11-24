<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Setting;
use App\Models\SmsDoNotSend;
use App\Models\SmsLog;
use App\Services\Sms\AmarSmsGateway;
use App\Services\Sms\NishchitGateway;
use App\Services\Sms\SmsGateway;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * M12's one SMS sender. Every SMS the platform sends for a shop goes through
 * here: parts are priced, the prepaid balance is charged, marketing sends
 * honour the do-not-send list and the frequency cap, and every attempt is
 * written to sms_logs — the SMS Report's attribution is computed from them.
 *
 * Each shop sends through its OWN provider account (Settings → Marketing
 * SMS): per-business keys marketing.{biz}.sms_provider / sms_mode /
 * sms_sender_id / sms_api_key_enc. Demo mode only logs the send; Live mode
 * calls the provider through a SmsGateway driver.
 */
class SmsService
{
    /** Automation flows that count as marketing (cap + do-not-send apply). */
    public const MARKETING_FLOWS = ['reminder', 'winback', 'back_in_stock', 'post_return'];

    public function __construct(private readonly SettingsService $settings) {}

    public function setting(int $biz, string $name, mixed $default = null): mixed
    {
        return $this->settings->get("marketing.{$biz}.{$name}", $default);
    }

    public function setSetting(int $biz, string $name, mixed $value): void
    {
        $this->settings->set("marketing.{$biz}.{$name}", $value);
    }

    /** Settings → Marketing SMS saves go through the logged setter so the Change History covers them (Hunt W W3). */
    public function setSettingLogged(int $biz, string $name, mixed $value, ?\App\Models\User $actor): void
    {
        $this->settings->setLogged("marketing.{$biz}.{$name}", $value, $actor);
    }

    public function rate(int $biz): float
    {
        return (float) $this->setting($biz, 'sms_rate', 1.50);
    }

    public function balance(int $biz): float
    {
        return (float) $this->setting($biz, 'sms_balance', 0);
    }

    /** SMS parts: GSM-7 fits 160 chars/part, Unicode (Bangla) 70; long messages use 153/67. */
    public function parts(string $body): int
    {
        $len = mb_strlen($body);
        if ($len === 0) {
            return 1;
        }
        $unicode = (bool) preg_match('/[^\x20-\x7E\r\n]/u', $body);
        if ($unicode) {
            return $len <= 70 ? 1 : (int) ceil($len / 67);
        }

        return $len <= 160 ? 1 : (int) ceil($len / 153);
    }

    public function isMarketing(string $sourceType, string $sourceKey): bool
    {
        return $sourceType === 'campaign'
            || ($sourceType === 'automation' && in_array($sourceKey, self::MARKETING_FLOWS, true));
    }

    public function onDoNotSend(int $biz, string $phone): bool
    {
        return SmsDoNotSend::where('business_id', $biz)->where('phone', $phone)->exists();
    }

    /**
     * Why a marketing send to this phone would be skipped right now:
     * 'dnd', 'cap', or null when it would really go out. The campaign
     * preview and the sender share this one check, so the number of
     * recipients the seller is promised is the number the send reaches.
     * With $locked, the counts are taken as locking (current) reads -
     * send() uses that variant inside its balance-lock transaction, so
     * a concurrent send's just-committed log row is always counted
     * (Hunt Z1-2); the preview keeps the plain snapshot read.
     */
    public function marketingSkipReason(int $biz, string $phone, bool $locked = false): ?string
    {
        $phone = PhoneNormalizer::normalize($phone);
        if ($this->onDoNotSend($biz, $phone)) {
            return 'dnd';
        }
        [$today, $week] = $locked ? $this->marketingSentCountsLocked($biz, $phone) : $this->marketingSentCounts($biz, $phone);
        if ($today >= (int) $this->setting($biz, 'cap_day', 1) || $week >= (int) $this->setting($biz, 'cap_week', 3)) {
            return 'cap';
        }

        return null;
    }

    private function marketingSentQuery(int $biz, string $phone)
    {
        return SmsLog::where('business_id', $biz)->where('phone', $phone)->where('status', 'sent')
            ->where(function ($q) {
                $q->where('source_type', 'campaign')
                    ->orWhereIn('source_key', self::MARKETING_FLOWS);
            });
    }

    /** Marketing sends already delivered to this phone today / in the last 7 days. */
    public function marketingSentCounts(int $biz, string $phone): array
    {
        $base = $this->marketingSentQuery($biz, $phone);
        $today = (clone $base)->whereDate('created_at', Carbon::today())->count();
        $week = (clone $base)->where('created_at', '>=', Carbon::now()->subDays(7))->count();

        return [$today, $week];
    }

    /** marketingSentCounts() as locking reads (see marketingSkipReason). */
    private function marketingSentCountsLocked(int $biz, string $phone): array
    {
        $base = $this->marketingSentQuery($biz, $phone);
        $today = (clone $base)->whereDate('created_at', Carbon::today())->lockForUpdate()->pluck('id')->count();
        $week = (clone $base)->where('created_at', '>=', Carbon::now()->subDays(7))->lockForUpdate()->pluck('id')->count();

        return [$today, $week];
    }

    /**
     * Send one SMS. $source = ['type' => campaign|automation, 'key' => ..., 'label' => ..., 'ref' => ?string]
     * Always returns the log row; status tells what happened.
     */
    public function send(int $biz, string $phone, string $body, array $source, ?int $customerId = null): SmsLog
    {
        $phone = PhoneNormalizer::normalize($phone);
        $parts = $this->parts($body);
        $cost = round($parts * $this->rate($biz), 2);
        $marketing = $this->isMarketing($source['type'], $source['key']);

        $row = [
            'business_id' => $biz, 'phone' => $phone, 'customer_id' => $customerId,
            'source_type' => $source['type'], 'source_key' => (string) $source['key'],
            'source_label' => $source['label'] ?? (string) $source['key'], 'ref' => $source['ref'] ?? null,
            'body' => $body, 'parts' => $parts, 'cost' => 0,
        ];

        // Exactly-once charging (Hunt Z1-2): the cap check, the
        // balance check and the decrement are one atomic step. The
        // shop's balance settings row is locked for the whole send,
        // so two overlapping sends serialise on it - the second send
        // re-reads the balance from the locked row and counts today's
        // sends as locking reads only after the first send has
        // committed, and can pass neither check on stale state (the
        // old code read the balance, dispatched, then wrote back
        // balance - cost: two sends on one SMS of credit both landed,
        // and the lost update left the books reading a clean 0).
        // A refused send writes only its own log row - the balance
        // and the counts stay untouched.
        return DB::transaction(function () use ($biz, $phone, $body, $cost, $marketing, $row) {
            $balanceKey = "marketing.{$biz}.sms_balance";
            DB::table('settings')->insertOrIgnore([
                'key' => $balanceKey, 'value' => '0', 'type' => 'number', 'group' => 'marketing',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $balanceRow = Setting::where('key', $balanceKey)->lockForUpdate()->first();
            $balance = (float) ($balanceRow?->decodedValue() ?? 0);

            if ($marketing) {
                $skip = $this->marketingSkipReason($biz, $phone, locked: true);
                if ($skip !== null) {
                    $row['status'] = $skip === 'dnd' ? 'skipped_dnd' : 'skipped_cap';

                    return SmsLog::create($row);
                }
            }
            if ($balance < $cost) {
                $row['status'] = 'failed';
                $row['error'] = 'Not enough SMS balance';

                return SmsLog::create($row);
            }

            $dispatch = $this->dispatch($biz, $phone, $body);
            $row['provider'] = $dispatch['provider'];
            $row['provider_message_id'] = $dispatch['provider_message_id'];
            $row['provider_response'] = $dispatch['response'];
            if (! $dispatch['ok']) {
                $row['status'] = 'failed';
                $row['error'] = $dispatch['error'] !== null ? mb_substr($dispatch['error'], 0, 250) : null;

                return SmsLog::create($row);
            }

            $this->settings->set($balanceKey, round($balance - $cost, 2));
            $row['status'] = 'sent';
            $row['cost'] = $cost;

            return SmsLog::create($row);
        });
    }

    /**
     * The shop's own SMS provider configuration. The API key is stored
     * encrypted (sms_api_key_enc) with a plain 'set' marker (sms_api_key),
     * the same pattern the gateway key used before it became per-seller.
     *
     * OWNERSHIP BOUNDARY (owner rule): customer messages resolve ONLY the
     * shop's own key — never another shop's and never the platform's.
     * Before per-seller keys existed, one gateway key lived in the
     * platform-global settings (marketing.gateway_api_key_enc); that key
     * belongs to the PLATFORM, so it is consulted only when $platform is
     * true — the platform's own sends (sendPlatform: verification codes),
     * resolved against the platform business's configuration.
     */
    public function providerConfig(int $biz, bool $platform = false): array
    {
        $enc = (string) $this->setting($biz, 'sms_api_key_enc', '');
        if ($enc === '' && $platform) {
            $enc = (string) $this->settings->get('marketing.gateway_api_key_enc', '');
        }
        $key = null;
        if ($enc !== '') {
            try {
                $key = Crypt::decryptString($enc);
            } catch (\Throwable) {
                $key = null;
            }
        }

        return [
            'provider' => (string) $this->setting($biz, 'sms_provider', 'nishchit'),
            'mode' => (string) $this->setting($biz, 'sms_mode', 'demo'),
            'sender_id' => (string) $this->setting($biz, 'sms_sender_id', ''),
            'api_key' => $key,
            'has_key' => $key !== null && $key !== '',
        ];
    }

    /** The gateway driver for a shop; a typed key wins over the saved one. */
    public function gateway(int $biz, ?string $typedKey = null, bool $platform = false): ?SmsGateway
    {
        $cfg = $this->providerConfig($biz, $platform);
        $key = $typedKey !== null && trim($typedKey) !== '' ? trim($typedKey) : (string) ($cfg['api_key'] ?? '');

        return match ($cfg['provider']) {
            'nishchit' => new NishchitGateway($key, $cfg['sender_id'] !== '' ? $cfg['sender_id'] : null),
            'amarsms' => new AmarSmsGateway($key, $cfg['sender_id'] !== '' ? $cfg['sender_id'] : null),
            default => null,
        };
    }

    /**
     * The shop's webhook signing secret for its provider account (stored
     * encrypted as sms_webhook_secret_enc), or null when none is saved.
     * The SMS webhook controller verifies delivery events against it.
     */
    public function webhookSecret(int $biz): ?string
    {
        $secret = $this->ownWebhookSecret($biz);
        if ($secret !== null) {
            return $secret;
        }

        // Platform-dispatched sends (verification codes for a shop with no
        // gateway of its own) go out through the platform business's
        // account (business 1) but are logged under the shop, and their
        // delivery events are signed with the platform account's secret.
        // Mirror the send-side fallback, or those events can never verify
        // (live proof 2026-11-22/23: a Wizard Test Shop storefront OTP
        // report was rejected 8 times with "no webhook secret saved").
        if ($biz !== 1 && ! $this->providerConfig($biz)['has_key']) {
            return $this->ownWebhookSecret(1);
        }

        return null;
    }

    /** The shop's own saved webhook secret, or null when none is saved. */
    private function ownWebhookSecret(int $biz): ?string
    {
        $enc = (string) $this->setting($biz, 'sms_webhook_secret_enc', '');
        if ($enc === '') {
            return null;
        }
        try {
            $secret = Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }

        return $secret !== '' ? $secret : null;
    }

    /**
     * Settings "Check connection": reads the provider account balance with
     * the saved key (or the key typed into the form, which wins).
     *
     * @return array{ok: bool, balance: ?float, error: ?string}
     */
    public function testConnection(int $biz, ?string $typedKey = null): array
    {
        $cfg = $this->providerConfig($biz);
        $key = $typedKey !== null && trim($typedKey) !== '' ? trim($typedKey) : $cfg['api_key'];
        if (! $key) {
            return ['ok' => false, 'balance' => null, 'error' => 'No API key saved yet'];
        }
        $gateway = $this->gateway($biz, $typedKey);
        if (! $gateway) {
            return ['ok' => false, 'balance' => null, 'error' => 'Unknown SMS provider'];
        }
        if (! $gateway->configured()) {
            return ['ok' => false, 'balance' => null, 'error' => 'Gateway not configured'];
        }
        $balance = $gateway->balance();
        if ($balance === null) {
            return ['ok' => false, 'balance' => null, 'error' => 'Could not read the provider balance — check the API key'];
        }

        return ['ok' => true, 'balance' => $balance, 'error' => null];
    }

    /**
     * Hand one SMS to the outside world. Demo mode: nothing leaves the app,
     * the send is only logged (the long-standing local behaviour). Live mode:
     * the shop's own provider account is called and its answer is mapped
     * onto the log row.
     *
     * @return array{ok: bool, provider: ?string, provider_message_id: ?string, error: ?string, response: ?string}
     */
    private function dispatch(int $biz, string $phone, string $body, bool $platform = false): array
    {
        $cfg = $this->providerConfig($biz, $platform);
        if ($cfg['mode'] !== 'live') {
            return ['ok' => true, 'provider' => null, 'provider_message_id' => null, 'error' => null, 'response' => null];
        }
        if (! $cfg['has_key']) {
            return ['ok' => false, 'provider' => $cfg['provider'], 'provider_message_id' => null, 'error' => 'SMS gateway API key not saved', 'response' => null];
        }
        $gateway = $this->gateway($biz, null, $platform);
        if (! $gateway) {
            return ['ok' => false, 'provider' => $cfg['provider'], 'provider_message_id' => null, 'error' => 'Unknown SMS provider', 'response' => null];
        }
        $result = $gateway->send($phone, $body, $cfg['sender_id'] !== '' ? $cfg['sender_id'] : null);
        $response = $result['response'] ?? null;

        return [
            'ok' => (bool) $result['ok'],
            'provider' => $cfg['provider'],
            'provider_message_id' => $result['provider_message_id'] ?? null,
            'error' => $result['error'] ?? null,
            'response' => $response === null || is_string($response) ? $response : json_encode($response),
        ];
    }

    /**
     * Platform transactional send (verification codes and the like):
     * dispatched through the given business's provider configuration,
     * but NOT gated on — or charged to — that shop's prepaid marketing
     * balance. The in-app balance is a marketing construct; a one-time
     * code must never be blocked by it (a fresh shop has ৳0 and the
     * platform fallback shop may too, while its gateway account is
     * funded). The log row records the gateway's real answer, so the
     * caller can report success/failure truthfully.
     */
    public function sendPlatform(int $biz, string $phone, string $body, array $source, ?int $logBiz = null): SmsLog
    {
        $phone = PhoneNormalizer::normalize($phone);
        // Platform resolution: the platform business's own config, with the
        // platform's legacy global key as ITS fallback — never a seller's.
        $dispatch = $this->dispatch($biz, $phone, $body, platform: true);

        return SmsLog::create([
            'business_id' => $logBiz ?? $biz, 'phone' => $phone, 'customer_id' => null,
            'source_type' => $source['type'], 'source_key' => (string) $source['key'],
            'source_label' => $source['label'] ?? (string) $source['key'], 'ref' => $source['ref'] ?? null,
            'body' => $body, 'parts' => $this->parts($body), 'cost' => 0,
            'status' => $dispatch['ok'] ? 'sent' : 'failed',
            'provider' => $dispatch['provider'],
            'provider_message_id' => $dispatch['provider_message_id'],
            'provider_response' => $dispatch['response'],
            'error' => $dispatch['ok'] ? null : ($dispatch['error'] !== null ? mb_substr($dispatch['error'], 0, 250) : null),
        ]);
    }

    /**
     * Settings test SMS: one message through the shop's current mode,
     * bypassing the frequency caps and the prepaid-balance charge (the
     * seller is testing their own provider account), but still written to
     * sms_logs like every send — source_type 'test', attached to no campaign.
     */
    public function sendTest(int $biz, string $phone, string $body): SmsLog
    {
        $phone = PhoneNormalizer::normalize($phone);
        $dispatch = $this->dispatch($biz, $phone, $body);

        return SmsLog::create([
            'business_id' => $biz, 'phone' => $phone, 'customer_id' => null,
            'source_type' => 'test', 'source_key' => 'settings', 'source_label' => 'Settings test SMS',
            'ref' => null, 'body' => $body, 'parts' => $this->parts($body), 'cost' => 0,
            'status' => $dispatch['ok'] ? 'sent' : 'failed',
            'provider' => $dispatch['provider'],
            'provider_message_id' => $dispatch['provider_message_id'],
            'provider_response' => $dispatch['response'],
            'error' => $dispatch['ok'] ? null : ($dispatch['error'] !== null ? mb_substr($dispatch['error'], 0, 250) : null),
        ]);
    }

    /**
     * Ready-made campaign audiences from the shop's own data.
     * Returns key => ['label' => string, 'phones' => string[] (unique, normalized)].
     */
    public function audiences(int $biz): array
    {
        $valid = fn ($q) => $q->where('business_id', $biz)->whereNotIn('status', ['Cancelled']);

        $all = Customer::where('business_id', $biz)->pluck('phone')
            ->map(fn ($p) => PhoneNormalizer::normalize($p))->filter()->unique()->values()->all();

        $bought30 = $valid(Order::query())->where('created_at', '>=', Carbon::now()->subDays(30))
            ->pluck('customer_phone')->unique()->values()->all();

        $lastOrder = $valid(Order::query())->selectRaw('customer_phone, MAX(created_at) as last_at')
            ->groupBy('customer_phone')->pluck('last_at', 'customer_phone');
        $dormant = $lastOrder->filter(fn ($at) => Carbon::parse($at)->lt(Carbon::now()->subDays(60)))
            ->keys()->values()->all();

        $unconfirmed = Order::where('business_id', $biz)->where('status', 'Pending')
            ->whereDate('created_at', Carbon::today())->pluck('customer_phone')->unique()->values()->all();

        $outside = $valid(Order::query())->whereNotNull('district')->where('district', '!=', 'Dhaka')
            ->pluck('customer_phone')->unique()->values()->all();

        return [
            'all' => ['label' => __('All customers'), 'phones' => $all],
            'bought_30d' => ['label' => __('Bought in the last 30 days'), 'phones' => $bought30],
            'dormant_60d' => ['label' => __('Bought once, not back in 60 days'), 'phones' => $dormant],
            'unconfirmed_today' => ['label' => __("Today's unconfirmed orders"), 'phones' => $unconfirmed],
            'outside_dhaka' => ['label' => __('Customers outside Dhaka'), 'phones' => $outside],
        ];
    }

    public function doNotSendCount(int $biz): int
    {
        return SmsDoNotSend::where('business_id', $biz)->count();
    }
}
