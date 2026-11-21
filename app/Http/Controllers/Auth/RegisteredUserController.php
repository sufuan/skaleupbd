<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PointPack;
use App\Models\User;
use App\Services\PaymentClaimService;
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
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'shop_name' => 'required|string|max:120',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
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

        return redirect(route('dashboard', absolute: false));
    }
}
