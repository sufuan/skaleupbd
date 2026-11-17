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
    private function businessId(Request $request): int
    {
        return $request->user()->business_id;
    }

    /** Masked view of a method for the Settings page — secrets never leave the server. */
    public static function methodView(CourierMethod $m): array
    {
        $secretKeys = ['api_key', 'api_secret', 'client_secret', 'password', 'account_password', 'access_token', 'webhook_token', 'client_context'];
        $creds = [];
        foreach (($m->credentials ?? []) as $k => $v) {
            $isSecret = in_array($k, $secretKeys, true);
            $creds[$k] = [
                'set' => filled($v),
                'masked' => filled($v) ? '••••' . substr((string) $v, -2) : '',
                'value' => $isSecret ? null : (string) $v,
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
            'charge_inside_dhaka' => (float) $m->charge_inside_dhaka,
            'charge_outside_dhaka' => (float) $m->charge_outside_dhaka,
            'default_shipping_note' => $m->default_shipping_note,
            'webhook_url' => $m->webhook_url,
            'credentials' => $creds,
            'stores' => $m->pickupStores->map(fn ($s) => ['id' => $s->id, 'store_id' => $s->store_id, 'name' => $s->name, 'is_default' => $s->is_default])->all(),
        ];
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
        ]);
        $method = CourierMethod::create([
            'business_id' => $this->businessId($request),
            'name' => $data['name'],
            'courier_type' => $data['courier_type'],
            'charge_inside_dhaka' => $data['charge_inside_dhaka'] ?? 0,
            'charge_outside_dhaka' => $data['charge_outside_dhaka'] ?? 0,
            'default_shipping_note' => $data['default_shipping_note'] ?? null,
            'credentials' => collect($data['credentials'] ?? [])->filter(fn ($v) => filled($v))->all(),
            'is_active' => true,
        ]);
        $method->update(['webhook_url' => url('/api/webhooks/' . strtolower($method->courier_type) . '/dm/' . $method->id)]);
        $this->syncMethodList($request);

        return back()->with('success', 'Courier saved');
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
        ]);
        // Blank credential fields keep the saved secret; a filled field replaces it.
        $creds = $courier->credentials ?? [];
        foreach (($data['credentials'] ?? []) as $k => $v) {
            if (filled($v)) {
                $creds[$k] = $v;
            }
        }
        $courier->update([
            'name' => $data['name'],
            'charge_inside_dhaka' => $data['charge_inside_dhaka'] ?? 0,
            'charge_outside_dhaka' => $data['charge_outside_dhaka'] ?? 0,
            'default_shipping_note' => $data['default_shipping_note'] ?? null,
            'credentials' => $creds,
        ]);
        $this->syncMethodList($request);

        return back()->with('success', 'Courier saved');
    }

    public function toggle(Request $request, CourierMethod $courier)
    {
        abort_unless($courier->business_id === $this->businessId($request), 404);
        $courier->update(['is_active' => ! $courier->is_active]);
        $this->syncMethodList($request);

        return back()->with('success', $courier->is_active ? 'Courier turned on' : 'Courier turned off');
    }

    public function setDefault(Request $request, CourierMethod $courier)
    {
        abort_unless($courier->business_id === $this->businessId($request), 404);
        CourierMethod::where('business_id', $courier->business_id)->update(['is_default' => false]);
        $courier->update(['is_default' => true, 'is_active' => true]);

        return back()->with('success', 'Default courier set');
    }

    public function setPreferred(Request $request, CourierMethod $courier)
    {
        abort_unless($courier->business_id === $this->businessId($request), 404);
        CourierMethod::where('business_id', $courier->business_id)->update(['is_preferred' => false]);
        $courier->update(['is_preferred' => true, 'is_active' => true]);

        return back()->with('success', 'Fallback courier set');
    }

    public function destroy(Request $request, CourierMethod $courier)
    {
        abort_unless($courier->business_id === $this->businessId($request), 404);
        $inFlight = Order::where('business_id', $courier->business_id)
            ->where('courier_method_id', $courier->id)
            ->where('status', 'Shipped')
            ->exists();
        if ($inFlight) {
            return back()->with('error', 'This courier still has parcels in transit — it cannot be removed until they are delivered or returned');
        }
        $courier->delete();
        $this->syncMethodList($request);

        return back()->with('success', 'Courier removed');
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

        return back()->with('success', count($stores) ? count($stores) . ' pickup stores synced' : 'No stores returned by the courier');
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

        return back()->with('success', 'Rule added');
    }

    public function destroyRule(Request $request, CourierAssignmentRule $rule)
    {
        abort_unless($rule->business_id === $this->businessId($request), 404);
        $rule->delete();

        return back()->with('success', 'Rule removed');
    }

    /** Keep the M2 form-facing method list in step with active connections. */
    private function syncMethodList(Request $request): void
    {
        $settings = app(\App\Services\SettingsService::class);
        $methods = $settings->get('couriers.methods', []);
        $active = CourierMethod::where('business_id', $this->businessId($request))->where('is_active', true)->get();
        foreach ($active as $m) {
            if ($m->bookingMode() === 'manual') {
                if (! in_array($m->label(), $methods, true)) {
                    $methods[] = $m->label();
                }
            } else {
                foreach (['Inside Dhaka', 'Outside Dhaka'] as $zone) {
                    $entry = $m->label() . ' — ' . $zone;
                    if (! in_array($entry, $methods, true)) {
                        $methods[] = $entry;
                    }
                }
            }
        }
        $settings->set('couriers.methods', array_values($methods));
    }
}
