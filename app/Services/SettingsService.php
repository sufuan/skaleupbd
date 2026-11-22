<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/** Key/value settings store (same pattern as TPDA e-Sheba). */
class SettingsService
{
    private const CACHE_PREFIX = 'settings.';

    public function get(string $key, mixed $default = null): mixed
    {
        $cached = Cache::rememberForever(self::CACHE_PREFIX.$key, function () use ($key) {
            $setting = Setting::where('key', $key)->first();

            return $setting ? ['found' => true, 'value' => $setting->decodedValue()] : ['found' => false, 'value' => null];
        });

        return $cached['found'] ? $cached['value'] : $default;
    }

    /**
     * The name a shop trades under. The profile form saves a per-business
     * override (business.{id}.profile_name); until a seller saves one,
     * their registered business name is the truth. The old global
     * business.name was shared by every seller — they all saw the first
     * shop's name ("Demo Shop") on their settings, invoices and slips.
     */
    public function shopName(int $biz): string
    {
        $saved = trim((string) $this->get("business.{$biz}.profile_name", ''));
        if ($saved !== '') {
            return $saved;
        }

        return (string) (\App\Models\Business::whereKey($biz)->value('name') ?? '');
    }

    /** Per-business profile contact field (mobile|address), legacy global as fallback. */
    public function shopContact(int $biz, string $field): string
    {
        $v = $this->get("business.{$biz}.{$field}", null);

        return (string) ($v ?? $this->get("business.{$field}", ''));
    }

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        $setting = Setting::where('key', $key)->first();
        // A numeric STRING is only stored as a number when it is the
        // canonical form — '017…' phone numbers and other leading-zero
        // codes must stay strings or they lose the zero on read-back.
        $numeric = is_int($value) || is_float($value)
            || (is_string($value) && is_numeric($value) && (string) ($value + 0) === $value);
        $type = $setting?->type ?? (is_bool($value) ? 'boolean' : (is_array($value) ? 'json' : ($numeric ? 'number' : 'string')));

        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => Setting::encodeValue($value, $type), 'type' => $type, 'group' => $setting?->group ?? strstr($key, '.', true) ?: 'general', 'updated_by' => $userId]
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }
}
