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
     * Read a per-business shop preference. The shop's own namespaced key
     * wins; until the shop saves one, the LEGACY global key (the value
     * every shop shared before namespacing) is the fallback, so no shop
     * loses its current setting. Writes always go to the namespaced key
     * via set() - the legacy rows are read-only fallbacks now.
     */
    public function bizGet(int $biz, string $scopedKey, string $legacyKey, mixed $default = null): mixed
    {
        // Business 0 is not a shop: a platform admin outside view-as
        // must never read a real shop's legacy global as if it were a
        // phantom shop's own value (that is how one shop's phone and
        // delivery charge appeared on the platform admin's Settings).
        if ($biz <= 0) {
            return $default;
        }
        $own = $this->get($scopedKey, null);

        return $own !== null ? $own : $this->get($legacyKey, $default);
    }

    /**
     * The name a shop trades under (Hunt Z4-M3): the businesses row is
     * the ONE source - the Business Profile form writes it there, the
     * seller header and the platform Sellers list read it there. The
     * old per-business settings override (business.{id}.profile_name)
     * is only a fallback for a row whose name was never set; the form
     * no longer writes it as the primary store, so the two can no
     * longer disagree (invoices printed the settings name while the
     * seller's own header kept showing the registered one).
     */
    public function shopName(int $biz): string
    {
        if ($biz <= 0) {
            return '';
        }
        $name = trim((string) (\App\Models\Business::whereKey($biz)->value('name') ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) $this->get("business.{$biz}.profile_name", ''));
    }

    /**
     * Profile contact field (mobile|address) (Hunt Z4-M3): the
     * businesses row (phone / address columns) is the one source, for
     * the same reason as shopName(); the per-business settings key and
     * then the legacy global remain as fallbacks for values saved
     * before the row became the store.
     */
    public function shopContact(int $biz, string $field): string
    {
        // No shop in scope (platform admin): never surface the legacy
        // global, which holds a real shop's contact details.
        if ($biz <= 0) {
            return '';
        }
        if ($field === 'mobile' || $field === 'address') {
            $column = $field === 'mobile' ? 'phone' : 'address';
            $v = trim((string) (\App\Models\Business::whereKey($biz)->value($column) ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
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

    /**
     * set() + a Settings Change History row (settings_changes), in the
     * exact shape SettingsController::logChange writes: booleans read
     * On/Off, arrays are JSON, and the row carries the actor's business
     * and name. Only an actual change is logged. Secret values stay
     * safe the same way the controller's saves keep them safe: callers
     * pass the encrypted form, and the history view masks *_enc /
     * secret / token / password / api_key values at display time.
     * Every Settings save path (controller or service) routes through
     * this one logged setter so the history covers them all (Hunt W W3).
     */
    public function setLogged(string $key, mixed $value, ?\App\Models\User $actor): void
    {
        $old = $this->get($key);
        $this->set($key, $value, $actor?->id);
        if (json_encode($old) === json_encode($value)) {
            return;
        }
        $fmt = fn (mixed $v) => is_bool($v) ? ($v ? 'On' : 'Off') : (is_array($v) ? json_encode($v) : (string) ($v ?? ''));
        \Illuminate\Support\Facades\DB::table('settings_changes')->insert([
            'key' => $key,
            'business_id' => $actor?->business_id,
            'old_value' => $fmt($old),
            'new_value' => $fmt($value),
            'changed_by' => $actor?->name,
            'created_at' => now(),
        ]);
    }
}
