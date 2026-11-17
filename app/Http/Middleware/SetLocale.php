<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active UI locale for every web request (TPDA pattern).
 * Priority: user's saved locale → session → locale cookie → settings
 * default (app.locale_default) → config default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = null;

        $user = $request->user();
        if ($user && isset($user->locale) && $user->locale) {
            $locale = $user->locale;
        }

        $locale ??= $request->session()->get('locale');
        $locale ??= $request->cookie('locale');

        if (! $locale && \Illuminate\Support\Facades\Schema::hasTable('settings')) {
            $locale = rescue(fn () => app(\App\Services\SettingsService::class)->get('app.locale_default'), null, false);
        }

        $locale ??= config('app.locale', 'en');

        if (! in_array($locale, ['en', 'bn'], true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
