<?php

namespace App\Services;

use App\Models\RegistrationOtp;
use App\Models\User;
use App\Notifications\RegistrationOtpNotification;
use Illuminate\Support\Facades\Hash;

/**
 * Registration verification (owner order, 2026-11-22).
 *
 * Platform Settings hold two switches: registration.verify_enabled
 * (default OFF — registration then behaves exactly as before and
 * nothing is ever sent) and registration.verify_channel
 * (sms | email | both, default email). When ON, a new seller gets ONE
 * 6-digit code — stored only as a hash, 10-minute expiry, single-use,
 * max 5 attempts, 60-second resend cooldown — and is gated to the
 * verification page until they enter it. SMS goes through the
 * PLATFORM SMS path (platform-paid, the same boundary as the
 * storefront seller-verify OTPs); email is a branded notification.
 */
class RegistrationVerificationService
{
    public const MAX_ATTEMPTS = 5;

    public const TTL_MINUTES = 10;

    public const RESEND_COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SmsService $sms,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('registration.verify_enabled', false);
    }

    public function channel(): string
    {
        $channel = (string) $this->settings->get('registration.verify_channel', 'email');

        return in_array($channel, ['sms', 'email', 'both'], true) ? $channel : 'email';
    }

    public function rowFor(User $user): ?RegistrationOtp
    {
        return RegistrationOtp::where('user_id', $user->id)->first();
    }

    /**
     * True while this user still owes us a correct code: the switch is
     * ON and they hold an unverified OTP row. Users who registered
     * while the switch was OFF have no row and are never gated.
     */
    public function needsVerification(User $user): bool
    {
        if (! $this->enabled() || $user->is_platform_admin) {
            return false;
        }
        $row = $this->rowFor($user);

        return $row !== null && $row->verified_at === null;
    }

    /**
     * Issue a fresh code for the user and send it on the configured
     * channel(s). Any earlier code dies. Returns which sends landed:
     * ['sent' => bool, 'email_sent' => bool, 'sms_sent' => bool, 'channel' => string].
     */
    public function begin(User $user): array
    {
        $channel = $this->channel();
        $code = (string) random_int(100000, 999999);

        $row = RegistrationOtp::updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($code),
                'channels' => $channel,
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'last_sent_at' => now(),
                'verified_at' => null,
            ]
        );

        return $this->dispatch($user, $row, $code, $channel);
    }

    /**
     * Resend, honouring the 60-second cooldown. A resend issues a NEW
     * code (the old one stops working) and resets the attempt count.
     * Returns ['ok' => bool, 'wait' => seconds] merged with begin()'s
     * result when a code actually went out.
     */
    public function resend(User $user): array
    {
        $row = $this->rowFor($user);
        if (! $row || $row->verified_at !== null) {
            return ['ok' => false, 'wait' => 0];
        }
        $wait = $this->cooldownSeconds($user);
        if ($wait > 0) {
            return ['ok' => false, 'wait' => $wait];
        }
        $result = $this->begin($user);

        return ['ok' => $result['sent'], 'wait' => 0] + $result;
    }

    public function cooldownSeconds(User $user): int
    {
        $row = $this->rowFor($user);
        if (! $row || ! $row->last_sent_at) {
            return 0;
        }

        return max(0, self::RESEND_COOLDOWN_SECONDS - (int) $row->last_sent_at->diffInSeconds(now()));
    }

    /**
     * Check a typed code. Returns 'ok' | 'wrong' | 'expired' |
     * 'locked' (5 wrong tries — a fresh code is needed) | 'none'
     * (nothing waiting / already verified).
     */
    public function verify(User $user, string $code): string
    {
        $row = $this->rowFor($user);
        if (! $row || $row->verified_at !== null || ! $row->code_hash) {
            return 'none';
        }
        if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
            return 'locked';
        }
        if (! $row->expires_at || $row->expires_at->isPast()) {
            return 'expired';
        }
        if (! Hash::check(trim($code), $row->code_hash)) {
            $row->increment('attempts');

            return (int) $row->attempts >= self::MAX_ATTEMPTS ? 'locked' : 'wrong';
        }
        $row->forceFill(['verified_at' => now(), 'code_hash' => null, 'expires_at' => null])->save();

        return 'ok';
    }

    /** a***@domain — enough for the seller to recognise their inbox. */
    public function maskedEmail(User $user): string
    {
        $email = (string) $user->email;
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return $email;
        }

        return $email[0].'***'.substr($email, $at);
    }

    /** 017*****78 — enough to recognise the phone, never the full number. */
    public function maskedPhone(User $user): string
    {
        $phone = $user->business_id
            ? $this->settings->shopContact((int) $user->business_id, 'mobile')
            : '';
        $phone = trim((string) $phone);
        if (strlen($phone) < 7) {
            return $phone;
        }

        return substr($phone, 0, 3).str_repeat('*', strlen($phone) - 5).substr($phone, -2);
    }

    private function dispatch(User $user, RegistrationOtp $row, string $code, string $channel): array
    {
        $emailSent = false;
        $smsSent = false;

        if ($channel === 'email' || $channel === 'both') {
            try {
                $user->notify(new RegistrationOtpNotification($code));
                $emailSent = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if ($channel === 'sms' || $channel === 'both') {
            $smsSent = $this->sendSms($user, $code);
        }

        if (! $emailSent && ! $smsSent) {
            // The code never left the building — it must not be usable
            // if it leaks. A resend issues a fresh one.
            $row->forceFill(['code_hash' => null, 'expires_at' => null])->save();
        }

        return [
            'sent' => $emailSent || $smsSent,
            'email_sent' => $emailSent,
            'sms_sent' => $smsSent,
            'channel' => $channel,
        ];
    }

    /**
     * Platform-paid SMS through SmsService's platform path — the same
     * boundary as the storefront seller-verify OTP: dispatched with the
     * seller's own gateway config if they somehow have one, else the
     * platform's (business 1), and never charged to any shop balance.
     */
    private function sendSms(User $user, string $code): bool
    {
        $biz = (int) ($user->business_id ?? 0);
        if ($biz < 1) {
            return false;
        }
        $phone = trim($this->settings->shopContact($biz, 'mobile'));
        if ($phone === '') {
            return false;
        }

        $body = "Your Scalenow Bd verification code is {$code}. It is valid for 10 minutes. Do not share it with anyone.";
        $source = ['type' => 'transactional', 'key' => 'registration_otp', 'label' => 'Registration verification'];

        $via = $biz;
        if ($biz !== 1 && ! $this->sms->providerConfig($biz)['has_key']) {
            $via = 1;
        }
        $log = $this->sms->sendPlatform($via, $phone, $body, $source, $biz);

        return $log->status === 'sent';
    }
}
