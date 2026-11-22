<?php

namespace App\Http\Middleware;

use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M19 view-only gate: when a business's points cannot cover a new
 * order, the account goes view-only — every page still loads (GET),
 * but every mutating request is refused with the plain top-up message
 * until a pack purchase lands. Platform admins are never gated, and
 * account-keeping routes (logout, TOTP, language, email verification)
 * stay open so a blocked seller can still manage their sign-in. The
 * billing claim routes stay open too — paying is how a blocked seller
 * gets unblocked.
 */
class TokenGate
{
    public function __construct(private readonly TokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $request->isMethod('GET') && ! $user->is_platform_admin && $user->business_id) {
            $name = (string) ($request->route()?->getName() ?? '');
            $exempt = $name === 'logout' || $name === 'locale.switch' || $name === 'verification.send'
                || $name === 'sidebar.counts.seen' // clearing a badge is view state, not a shop mutation
                || $name === 'registration.verify.store' || $name === 'registration.verify.resend'
                || $name === 'billing.claims.store' || $name === 'billing.claims.submit'
                || str_starts_with($name, 'totp.');
            if (! $exempt && $this->tokens->blocked((int) $user->business_id)) {
                if ($request->expectsJson() || $request->is('api/*')) {
                    return response()->json(['message' => TokenService::BLOCK_MESSAGE], 402);
                }

                return redirect()->back()->with('error', TokenService::BLOCK_MESSAGE);
            }
        }

        return $next($request);
    }
}
