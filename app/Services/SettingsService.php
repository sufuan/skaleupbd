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

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        $setting = Setting::where('key', $key)->first();
        $type = $setting?->type ?? (is_bool($value) ? 'boolean' : (is_array($value) ? 'json' : (is_numeric($value) ? 'number' : 'string')));

        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => Setting::encodeValue($value, $type), 'type' => $type, 'group' => $setting?->group ?? strstr($key, '.', true) ?: 'general', 'updated_by' => $userId]
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }
}
