<?php

namespace App\Http\Controllers;

use App\Services\PaymentGatewayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Payment Gateway settings — one controller for both scopes. The
 * scope is resolved from the authenticated user on the server:
 * updateSeller always writes the caller's own business scope;
 * updatePlatform requires a platform admin and writes the platform
 * scope. There is no way for a request to name another scope.
 */
class PaymentGatewayController extends Controller
{
    public function __construct(private readonly PaymentGatewayService $gateway) {}

    private function rules(): array
    {
        return [
            'bkash_number' => ['nullable', 'string', 'max:30'],
            'bkash_enabled' => ['required', 'boolean'],
            'nagad_number' => ['nullable', 'string', 'max:30'],
            'nagad_enabled' => ['required', 'boolean'],
            'rocket_number' => ['nullable', 'string', 'max:30'],
            'rocket_enabled' => ['required', 'boolean'],
            'ssl_store_id' => ['nullable', 'string', 'max:80'],
            'ssl_enabled' => ['required', 'boolean'],
            'ssl_password' => ['nullable', 'string', 'max:200'],
            'shurjo_username' => ['nullable', 'string', 'max:80'],
            'shurjo_enabled' => ['required', 'boolean'],
            'shurjo_password' => ['nullable', 'string', 'max:200'],
            'instructions' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function updateSeller(Request $request): RedirectResponse
    {
        $businessId = $request->user()?->business_id;
        abort_unless($businessId, 403);
        $data = $request->validate($this->rules());
        $this->gateway->save((int) $businessId, $data, $request->user()->id);

        return back()->with('success', 'Your payment gateway settings were saved.');
    }

    public function updatePlatform(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->is_platform_admin, 403);
        $data = $request->validate($this->rules());
        $this->gateway->save(null, $data, $request->user()->id);

        return back()->with('success', 'Platform payment gateway settings were saved.');
    }
}
