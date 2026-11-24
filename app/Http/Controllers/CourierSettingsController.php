<?php

namespace App\Http\Controllers;

use App\Models\CourierAssignmentRule;
use App\Models\CourierMethod;
use App\Models\CourierPickupStore;
use App\Models\Order;
use App\Services\Couriers\DriverFactory;
use Illuminate\Http\Request;

class CourierSettingsController extends Controller
{
    /** Couriers whose drivers honour a 'sandbox' credential flag (test server). */
    private const SANDBOX_TYPES = ['PATHAO', 'CARRYBEE', 'REDX'];

    private function businessId(Request $request): int
    {
        $biz = \App\Support\ShopContext::id($request);
        // Every endpoint in this controller writes courier rows for one
        // shop. With no shop in scope (a platform admin outside
        // view-as) the old code created business_id 0 rows - refuse
        // before anything touches the database.
        abort_if($biz <= 0, 403, 'These settings belong to a shop. Sign in as a shop user to change them.');

        return $biz;
    }

    /**
     * Masked view of a method for the Settings page — secrets never
     * leave the server. In view-as-seller (ধাপ ৩, $redact) the whole
     * credentials block is redacted wholesale: no last-two-chars
     * masked suffix and no clear non-secret values either - a
     * read-only support view needs set/not-set only.
     */
    public static function methodView(CourierMethod $m, bool $redact = false): array
    {
        $secretKeys = ['api_key', 'api_secret', 'client_secret', 'password', 'account_password', 'access_token', 'webhook_token', 'webhook_secret', 'client_context', 'webhook_integration_pending_at'];
        $creds = [];
        foreach (($m->credentials ?? []) as $k => $v) {
            $isSecret = in_array($k, $secretKeys, true);
            $creds[$k] = [
                'set' => filled($v),
                'masked' => ! $redact && filled($v) ? '••••' . substr((string) $v, -2) : '',
                'value' => $redact || $isSecret ? null : (string) $v,
            ];
        }

        return [
            'id' => $m->id,
            'name' => $m->name,
            'courier_type' => $m->courier_type,
            'label' => $m->label(),
            'class' => $m->courierClass(),
            'booking_mode' => $m->bookingMode(),
            'is_active' => $m->is_active,
            'is_default' => $m->is_default,
            'is_preferred' => $m->is_preferred,
            'sandbox' => ! empty(($m->credentials ?? [])['sandbox']),
            'webhook_unverified' => $m->is_active && $m->webhookCredentialKey() !== null && ! $m->hasWebhookCredential(),
            'charge_inside_dhaka' => (float) $m->charge_inside_dhaka,
            'charge_outside_dhaka' => (float) $m->charge_outside_dhaka,
            'default_shipping_note' => $m->default_shipping_note,
            'webhook_url' => $m->webhook_url,
            'credentials' => $creds,
            'stores' => $m->pickupStores->map(fn ($s) => ['id' => $s->id, 'store_id' => $s->store_id, 'name' => $s->name, 'is_default' => $s->is_default])->all(),
        ];
    }

    /** Credential keys whose values must never land in the Change History in plain text. */
    private const HISTORY_SECRET_KEYS = ['api_key', 'api_secret', 'client_secret', 'password', 'account_password', 'access_token', 'webhook_token', 'webhook_secret', 'client_context', 'webhook_integration_pending_at'];

    /** History-safe snapshot of a courier connection: secret credential values are replaced by a set/empty marker. */
    private function courierSnapshot(CourierMethod $m): array
    {
        $creds = [];
        foreach (($m->credentials ?? []) as $k => $v) {
            $creds[$k] = in_array($k, self::HISTORY_SECRET_KEYS, true) ? (filled($v) ? 'set' : '') : $v;
        }

        return [
            'name' => $m->name,
            'courier_type' => $m->courier_type,
            'charge_inside_dhaka' => (float) $m->charge_inside_dhaka,
            'charge_outside_dhaka' => (float) $m->charge_outside_dhaka,
            'default_shipping_note' => $m->default_shipping_note,
            'is_active' => (bool) $m->is_active,
            'credentials' => $creds,
        ];
    }

    /** Courier saves are Settings saves too: write a Change History row (Hunt W W3). */
    private function logCourierChange(Request $request, CourierMethod $m, ?array $old): void
    {
        $new = $this->courierSnapshot($m);
        if ($old !== null && json_encode($old) === json_encode($new)) {
            return;
        }
        \Illuminate\Support\Facades\DB::table('settings_changes')->insert([
            'key' => 'courier.' . $m->id,
            'business_id' => $m->business_id,
            'old_value' => $old === null ? '' : (string) json_encode($old),
            'new_value' => (string) json_encode($new),
            'changed_by' => $request->user()?->name,
            'created_at' => now(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'courier_type' => 'required|in:' . implode(',', CourierMethod::TYPES),
            'charge_inside_dhaka' => 'nullable|numeric|min:0',
            'charge_outside_dhaka' => 'nullable|numeric|min:0',
            'default_shipping_note' => 'nullable|string|max:500',
            'credentials' => 'nullable|array',
            'sandbox' => 'nullable|boolean',
        ]);
        $creds = collect($data['credentials'] ?? [])->filter(fn ($v) => filled($v))->all();
        if (! empty($data['sandbox']) && in_array($data['courier_type'], self::SANDBOX_TYPES, true)) {
            $creds['sandbox'] = true;
        }
        // A freshly saved Pathao/Carrybee webhook secret opens the one-time
        // integration echo window (the seller pastes the webhook URL into
        // the courier panel right after saving here).
        if (in_array($data['courier_type'], ['PATHAO', 'CARRYBEE'], true) && filled($creds['webhook_secret'] ?? null)) {
            $creds['webhook_integration_pending_at'] = now()->toIso8601String();
        }
        $method = CourierMethod::create([
            'business_id' => $this->businessId($request),
            'name' => $data['name'],
            'courier_type' => $data['courier_type'],
            'charge_inside_dhaka' => $data['charge_inside_dhaka'] ?? 0,
            'charge_outside_dhaka' => $data['charge_outside_dhaka'] ?? 0,
            'default_shipping_note' => $data['default_shipping_note'] ?? null,
            'credentials' => $creds,
            'is_active' => true,
        ]);
        $method->update(['webhook_url' => url('/api/webhooks/' . strtolower($method->courier_type) . '/dm/' . $method->id)]);
        $this->logCourierChange($request, $method, null);
        $this->syncMethodList($request);

        return back()->with('success', __('Courier saved'));
    }

    public function update(Request $request, CourierMethod $courier)
    {
        abort_unless($courier->business_id === $this->businessId($request), 404);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'charge_inside_dhaka' => 'nullable|numeric|min:0',
            'charge_outside_dhaka' => 'nullable|numeric|min:0',
            'default_shipping_note' => 'nullable|string|max:500',
            'credentials' => 'nullable|array',
            'sandbox' => 'nullable|boolean',
        ]);
        // Blank credential fields keep the saved secret; a filled field replaces it.
        $creds = $courier->credentials ?? [];
        $secretRotated = false;
        foreach (($data['credentials'] ?? []) as $k => $v) {
            if (filled($v)) {
                if ($k === 'webhook_secret' && ($creds['webhook_secret'] ?? null) !== $v) {
                    $secretRotated = true;
                }
                $creds[$k] = $v;
            }
        }
        // Rotating the Pathao/Carrybee webhook secret re-opens the one-time
        // integration echo window, exactly like a first save.
        if ($secretRotated && in_array($courier->courier_type, ['PATHAO', 'CARRYBEE'], true)) {
            $creds['webhook_integration_pending_at'] = now()->toIso8601String();
        }
        // Sandbox is a plain on/off flag, not a secret: on = store true, off = remove the key.
        if (array_key_exists('sandbox', $data)) {
            if (! empty($data['sandbox']) && in_array($courier->courier_type, self::SANDBOX_TYPES, true)) {
                $creds['sandbox'] = true;
            } else {
                unset($creds['sandbox']);
            }
        }
        $oldSnapshot = $this->courierSnapshot($courier);
        $courier->update([
            'name' => $data['name'],
            'charge_inside_dhaka' => $data['charge_inside_dhaka'] ?? 0,
            'charge_outside_dhaka' => $data['charge_outside_dhaka'] ?? 0,
            'default_shipping_note' => $data['default_shipping_note'] ?? null,
            'credentials' => $creds,
        ]);
        $this->logCourierChange($request, $courier, $oldSnapshot);
        $this->syncMethodList($request);

        return back()->with('success', __('Courier saved'));
    }

    public function toggle(Request $request, CourierMethod $courier)
    {
        abort_unless($courier->business_id === $this->businessId($request), 404);
        $courier->update(['is_active' => ! $courier->is_active]);
        $this->syncMethodList($request);

        return back()->with('success', $courier->is_active ? __('Courier turned on') : __('Courier turned off'));
    }

    public function setDefault(Request $request, CourierMethod $courier)
    {
        abort_unless($courier->business_id === $this->businessId($request), 404);
        CourierMethod::where('business_id', $courier->business_id)->update(['is_default' => false]);
        // Default and Fallback are mutually exclusive roles: taking the
        // Default role hands the Fallback role back.
        $courier->update(['is_default' => true, 'is_preferred' => false, 'is_active' => true]);

        return back()->with('success', __('Default courier set'));
    }

    public function setPreferred(Request $request, CourierMethod $courier)
    {
        abort_unless($courier->business_id === $this->businessId($request), 404);
        CourierMethod::where('business_id', $courier->business_id)->update(['is_preferred' => false]);
        // Default and Fallback are mutually exclusive roles: taking the
        // Fallback role hands the Default role back.
        $courier->update(['is_preferred' => true, 'is_default' => false, 'is_active' => true]);

        return back()->with('success', __('Fallback courier set'));
    }

    public function destroy(Request $request, CourierMethod $courier)
    {
        abort_unless($courier->business_id === $this->businessId($request), 404);
        // S-F6: the guard counts every parcel that is not in a terminal
        // state - not only Shipped. A booked-but-not-shipped parcel
        // (Processing with a booking) used to slip through: the delete
        // nulled the order's courier_method_id and the parcel vanished
        // from the Parcels page while physically with the courier.
        $inFlight = Order::where('business_id', $courier->business_id)
            ->where('courier_method_id', $courier->id)
            ->whereNotIn('status', ['Delivered', 'Returned', 'Cancelled', 'Lost'])
            ->exists();
        if ($inFlight) {
            return back()->with('error', __('This courier still has booked or in-transit parcels - it cannot be removed until they are delivered, returned or cancelled'));
        }
        $courier->delete();
        $this->syncMethodList($request);

        return back()->with('success', __('Courier removed'));
    }

    public function testConnection(Request $request, CourierMethod $courier)
    {
        abort_unless($courier->business_id === $this->businessId($request), 404);
        $result = DriverFactory::for($courier)->testConnection($courier);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function syncStores(Request $request, CourierMethod $courier)
    {
        abort_unless($courier->business_id === $this->businessId($request), 404);
        $stores = DriverFactory::for($courier)->syncStores($courier);
        foreach ($stores as $s) {
            CourierPickupStore::updateOrCreate(
                ['courier_method_id' => $courier->id, 'store_id' => $s['store_id']],
                ['name' => $s['name']]
            );
        }

        return back()->with('success', count($stores) ? __(':count pickup stores synced', ['count' => count($stores)]) : __('No stores returned by the courier'));
    }

    public function storeRule(Request $request)
    {
        $data = $request->validate([
            'scope' => 'required|in:product,category,district,area',
            'product_id' => 'nullable|integer',
            'category' => 'nullable|string|max:120',
            'district' => 'nullable|string|max:120',
            'area' => 'nullable|string|max:120',
            'courier_method_id' => 'required|integer',
            'priority' => 'nullable|integer|min:1|max:99',
        ]);
        $method = CourierMethod::where('business_id', $this->businessId($request))->findOrFail($data['courier_method_id']);
        CourierAssignmentRule::create([
            'business_id' => $this->businessId($request),
            'scope' => $data['scope'],
            'product_id' => $data['scope'] === 'product' ? ($data['product_id'] ?? null) : null,
            'category' => $data['scope'] === 'category' ? ($data['category'] ?? null) : null,
            'district' => $data['scope'] === 'district' ? ($data['district'] ?? null) : null,
            'area' => $data['scope'] === 'area' ? ($data['area'] ?? null) : null,
            'courier_method_id' => $method->id,
            'priority' => $data['priority'] ?? 10,
            'is_active' => true,
        ]);

        return back()->with('success', __('Rule added'));
    }

    public function destroyRule(Request $request, CourierAssignmentRule $rule)
    {
        abort_unless($rule->business_id === $this->businessId($request), 404);
        $rule->delete();

        return back()->with('success', __('Rule removed'));
    }

    /** Keep the shop's own form-facing method list in step with its
     *  active connections (per-business list — see DeliveryMethodList). */
    private function syncMethodList(Request $request): void
    {
        app(\App\Services\DeliveryMethodList::class)->syncFromConnections($this->businessId($request));
    }
}
