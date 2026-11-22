<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PointPack;
use App\Models\User;
use App\Services\PaymentClaimService;
use App\Services\PhoneNormalizer;
use App\Services\RegistrationVerificationService;
use App\Services\SettingsService;
use App\Services\TokenService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * The pack a visitor picked on the pricing page (/register?pack=N).
     * Only an existing, active pack counts — anything else is ignored.
     */
    private function chosenPack(mixed $id): ?PointPack
    {
        if (! $id || ! is_numeric($id)) {
            return null;
        }

        return PointPack::where('id', (int) $id)->where('is_active', true)->first();
    }

    /**
     * Display the registration view.
     */
    public function create(Request $request): Response
    {
        $pack = $this->chosenPack($request->query('pack'));

        return Inertia::render('Auth/Register', [
            'pack' => $pack ? [
                'id' => $pack->id, 'name' => $pack->name, 'price_bdt' => (float) $pack->price_bdt,
                'credits' => (int) $pack->credits, 'validity_type' => $pack->validity_type,
            ] : null,
            'onboardingFee' => $pack ? app(TokenService::class)->onboardingFee() : 0,
            // Registration verification (Settings switch): the form
            // marks the mobile field required when a code will be sent
            // by SMS, and shows the "we will send a code" note.
            'verifyEnabled' => app(RegistrationVerificationService::class)->enabled(),
            'verifyChannel' => app(RegistrationVerificationService::class)->channel(),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // The mobile is only strictly required when a verification
        // code will actually be sent by SMS — with the switch OFF, or
        // email-only, signup keeps working exactly as before.
        $verify = app(RegistrationVerificationService::class);
        $mobileRequired = $verify->enabled() && $verify->channel() !== 'email';

        $request->validate([
            'name' => 'required|string|max:255',
            'shop_name' => 'required|string|max:120',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'mobile' => [$mobileRequired ? 'required' : 'nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'pack_id' => ['nullable'], // validated softly below — a bad pack never blocks signup
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'admin',
        ]);

        // Every account owns a shop: create its Business and the default
        // per-business data, and link the user to it.
        app(\App\Services\BusinessProvisioner::class)->provision($user, $request->input('shop_name'));

        // The register form's mobile becomes the shop's contact number
        // (the same per-business profile mobile Settings saves and the
        // storefront wizard's phone step pre-fills from).
        $mobile = PhoneNormalizer::normalize($request->input('mobile'));
        if ($mobile !== '' && $user->business_id) {
            app(SettingsService::class)->set("business.{$user->business_id}.mobile", $mobile);
        }

        // M19 purchase flow: a pack picked on the pricing page becomes a
        // pending payment claim, so Billing shows exactly what to pay.
        $pack = $this->chosenPack($request->input('pack_id'));
        if ($pack && $user->business_id) {
            try {
                app(PaymentClaimService::class)->createClaim((int) $user->business_id, $pack);
            } catch (\Throwable $e) {
                report($e); // a claim hiccup must never break registration
            }
        }

        // The new shop is pending approval — tell the platform admins.
        // Platform notice: business_id stays NULL on purpose, so only
        // platform admins ever see it (see AppNotification::visibleTo).
        if ($user->business_id) {
            try {
                \App\Models\AppNotification::create([
                    'kind' => 'seller',
                    'title' => 'New seller waiting for approval',
                    'body' => "{$user->name} ({$user->email}) just registered. Approve or reject the shop in Sellers.",
                    'link' => '/admin/sellers',
                ]);
            } catch (\Throwable $e) {
                report($e); // a notification hiccup must never break registration
            }
        }

        event(new Registered($user));

        Auth::login($user);

        // Registration verification (Settings switch, default OFF):
        // issue the code now; the gate keeps the seller on the code
        // page until they enter it. With the switch OFF nothing is
        // sent and signup lands on the dashboard as always.
        if ($verify->enabled()) {
            $sent = $verify->begin($user);

            return redirect(route('registration.verify', absolute: false))
                ->with($sent['sent'] ? 'success' : 'error', $sent['sent']
                    ? __('Your account was created. Enter the code we sent to finish.')
                    : __('Your account was created, but the code could not be sent. Please use the resend button.'));
        }

        return redirect(route('dashboard', absolute: false));
    }
}
