<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            // Quick-login button: shown when the dev login is enabled
            // (always in local; elsewhere only with DEV_LOGIN_ENABLED=true).
            'devLogin' => app()->isLocal() || config('app.dev_login_enabled'),
        ]);
    }

    /**
     * One-click login as the platform admin (no password).
     *
     * Owner-approved convenience for the pre-launch period. Enabled in the
     * local environment, or anywhere DEV_LOGIN_ENABLED=true is set in .env.
     * Every use is written to the log.
     */
    public function devLogin(Request $request): RedirectResponse
    {
        abort_unless(app()->isLocal() || config('app.dev_login_enabled'), 404);

        $user = \App\Models\User::where('is_platform_admin', true)->oldest('id')->first()
            ?? \App\Models\User::where('email', 'admin@admin.com')->first();

        abort_unless($user, 404);

        Auth::login($user, true);
        $request->session()->regenerate();

        \Illuminate\Support\Facades\Log::warning('Dev quick login used', [
            'user' => $user->email,
            'ip' => $request->ip(),
        ]);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
