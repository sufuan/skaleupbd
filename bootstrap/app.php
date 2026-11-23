<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: ['api/external/*', 'api/webhooks/*']);
        // sf_err carries a plain-language shop error message that the
        // storefront layout reads from document.cookie — it must not
        // be encrypted (it holds no sensitive data).
        $middleware->encryptCookies(except: ['sf_err']);
        $middleware->web(append: [
            \App\Http\Middleware\ResolveStorefrontHost::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\PermissionGate::class,
            \App\Http\Middleware\CheckInGate::class,
            \App\Http\Middleware\TokenGate::class,
            \App\Http\Middleware\SellerApprovalGate::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'totp' => \App\Http\Middleware\EnsureTotpVerified::class,
        ]);

        // M18: tenant host resolution must run after the session starts
        // but before Authenticate can bounce shop visitors to the login
        // page — pin it into the priority list ahead of Authenticate.
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \App\Http\Middleware\ResolveStorefrontHost::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
