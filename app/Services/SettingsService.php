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
        // The stored type follows the VALUE's type, never the row's old
        // type and never a guess from the string's shape: a numeric
        // STRING is an identifier (Meta app id, account id, phone
        // number) and must read back as the same string. Coercing
        // '1122334455' to number 1122334455 broke strict reads of
        // identifiers, while every real number in the app is cast to
        // int/float by its caller before set(), so nothing numeric is
        // lost by this rule.
        $type = is_bool($value) ? 'boolean' : (is_array($value) ? 'json' : ((is_int($value) || is_float($value)) ? 'number' : 'string'));

        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => Setting::encodeValue($value, $type), 'type' => $type, 'group' => $setting?->group ?? strstr($key, '.', true) ?: 'general', 'updated_by' => $userId]
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }
}
