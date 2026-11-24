<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication (same pattern as TPDA e-Sheba).
 * Active only while the master switch in Settings → Security & Records
 * (key security.totp_enabled, default OFF) is on. When the switch is off
 * nobody is forced to enroll or pass a challenge. While it is on, the
 * owner's settled scope (2026-10-26) applies: mandatory enrollment +
 * per-login challenge for super-admin / branch-manager / accountant —
 * in this app's roles, the platform admin plus the admin, manager and
 * accountant presets. Plain staff are never forced.
 */
class TotpService
{
    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly SettingsService $settings,
    ) {}

    /** Master switch for the whole TOTP system. Default OFF. */
    public function enabled(): bool
    {
        return (bool) $this->settings->get('security.totp_enabled', false);
    }

    public function requiredFor(User $user): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return (bool) $user->is_platform_admin
            || in_array((string) $user->role, ['admin', 'manager', 'accountant'], true);
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function qrCodeUrl(User $user, string $secret, string $issuer): string
    {
        return $this->google2fa->getQRCodeUrl($issuer, $user->email, $secret);
    }

    public function qrSvg(User $user, string $secret, string $issuer): string
    {
        $renderer = new ImageRenderer(new RendererStyle(200), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($this->qrCodeUrl($user, $secret, $issuer));
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, preg_replace('/\s+/', '', $code));
    }
}
