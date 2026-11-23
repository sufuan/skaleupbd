<?php

// Goes in: app/Http/Middleware/AuthenticateApiKey.php (new file).
// Used by all external API routes (api/external/*). Derives the business from the key credential only.
// Never trusts any header or body field for identity.
//
// Usage in routes/web.php:
//   Route::middleware(AuthenticateApiKey::class)->group(function () { ... });
//
// A matching scope is checked here if a 'required_scope' attribute is set on the route:
//   Route::get(...)->defaults('required_scope', 'orders.read');
// Or callers can call $request->apiKey()->grants($scope) themselves.

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = (string) $request->header('x-api-key', '');

        if ($raw === '') {
            return self::deny('API key missing.');
        }

        // findByRawKey is an indexed sha256 lookup and already excludes
        // revoked keys.
        $key = ApiKey::findByRawKey($raw);

        if (! $key) {
            return self::deny('Invalid or revoked API key.');
        }

        // Identity comes from the key alone: no header or body field is
        // ever read for the business (the kit's documented invariant).
        // Our ExternalOrderController additionally cross-checks a claimed
        // businessId on its own routes, which do not use this middleware.

        // Check the required scope if the route declares one.
        $requiredScope = $request->route()?->defaults['required_scope'] ?? null;
        if ($requiredScope && ! $key->grants((string) $requiredScope)) {
            return self::deny('This key does not have permission to perform this action.', 403);
        }

        // Attach the resolved key (and therefore the business) to the request.
        // Business id is read from $request->apiKey()->business_id everywhere downstream.
        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    // ------------------------------------------------------------------ helper

    private static function deny(string $message, int $status = 401): Response
    {
        return response()->json([
            'success'    => false,
            'statusCode' => $status,
            'requestId'  => null,
            'message'    => $message,
        ], $status)->header('Cache-Control', 'no-store');
    }
}
