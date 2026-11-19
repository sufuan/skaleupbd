<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\SmsDoNotSend;
use App\Models\SmsLog;
use Carbon\Carbon;

/**
 * M12's one SMS sender. Every SMS the platform sends for a shop goes through
 * here: parts are priced, the prepaid balance is charged, marketing sends
 * honour the do-not-send list and the frequency cap, and every attempt is
 * written to sms_logs — the SMS Report's attribution is computed from them.
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

    /** Marketing sends already delivered to this phone today / in the last 7 days. */
    public function marketingSentCounts(int $biz, string $phone): array
    {
        $base = SmsLog::where('business_id', $biz)->where('phone', $phone)->where('status', 'sent')
            ->where(function ($q) {
                $q->where('source_type', 'campaign')
                    ->orWhereIn('source_key', self::MARKETING_FLOWS);
            });
        $today = (clone $base)->whereDate('created_at', Carbon::today())->count();
        $week = (clone $base)->where('created_at', '>=', Carbon::now()->subDays(7))->count();

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

        if ($marketing && $this->onDoNotSend($biz, $phone)) {
            $row['status'] = 'skipped_dnd';

            return SmsLog::create($row);
        }
        if ($marketing) {
            [$today, $week] = $this->marketingSentCounts($biz, $phone);
            if ($today >= (int) $this->setting($biz, 'cap_day', 1) || $week >= (int) $this->setting($biz, 'cap_week', 3)) {
                $row['status'] = 'skipped_cap';

                return SmsLog::create($row);
            }
        }
        if ($this->balance($biz) < $cost) {
            $row['status'] = 'failed';

            return SmsLog::create($row);
        }

        // Driver: 'log' (local/demo — no gateway connected yet) marks the SMS
        // sent. A real gateway driver is added when a provider is connected in
        // Settings; until then a 'gateway' setting without credentials fails safe.
        $driver = (string) $this->settings->get('marketing.sms_driver', 'log');
        if ($driver === 'gateway' && ! $this->settings->get('marketing.gateway_api_key')) {
            $row['status'] = 'failed';

            return SmsLog::create($row);
        }

        $this->setSetting($biz, 'sms_balance', round($this->balance($biz) - $cost, 2));
        $row['status'] = 'sent';
        $row['cost'] = $cost;

        return SmsLog::create($row);
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
            'all' => ['label' => 'সব customer', 'phones' => $all],
            'bought_30d' => ['label' => 'শেষ 30 দিনে কিনেছে', 'phones' => $bought30],
            'dormant_60d' => ['label' => 'একবার কিনে 60 দিন আসেনি', 'phones' => $dormant],
            'unconfirmed_today' => ['label' => 'আজকের unconfirmed order', 'phones' => $unconfirmed],
            'outside_dhaka' => ['label' => 'ঢাকার বাইরের customer', 'phones' => $outside],
        ];
    }

    public function doNotSendCount(int $biz): int
    {
        return SmsDoNotSend::where('business_id', $biz)->count();
    }
}
