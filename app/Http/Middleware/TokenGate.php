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
 * gets unblocked. And the lifecycle of an order that already entered
 * (and was already charged) stays open: approve, status moves,
 * shipping and advance refunds create nothing new (Y1-F5).
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
                || $name === 'notifications.read' || $name === 'notifications.readAll' // same: marking read is view state
                || $name === 'push.subscribe' || $name === 'push.unsubscribe' // the user's own browser notification preference, not a shop mutation
                || $name === 'registration.verify.store' || $name === 'registration.verify.resend'
                || $name === 'billing.claims.store' || $name === 'billing.claims.submit'
                || $name === 'storefront.onboarding.save' // wizard draft steps: an unpaid seller must be able to walk onboarding; publish stays refused by the service (Rule 2)
                || $name === 'storefront.otp.send' || $name === 'storefront.otp.verify' // phone verification, same class as registration verify
                || $name === 'storefront.wizard.publish' // reaches the service so the Rule 2 refusal (not the generic block) surfaces; changes nothing while unpaid
                || str_starts_with($name, 'totp.')
                // Y1-F5 (owner rule): an order that already entered -
                // and was already charged its point - keeps its
                // lifecycle at zero balance: approving it, moving its
                // status, shipping it, tending its parcel and
                // refunding its advance create no new order and spend
                // no point. These routes are all bound to one existing
                // order, so none of them can create one. Order
                // CREATION (orders.store, chat drafts, storefront,
                // API) and every other mutation stay blocked.
                || in_array($name, [
                    'orders.approve', 'orders.update', 'orders.superedit.save', 'orders.refundAdvance',
                    'delivery.send', 'delivery.sendBulk', 'delivery.retry', 'delivery.changeCourier',
                    'delivery.cancelBooking', 'delivery.parcel.update', 'delivery.scan.apply', 'delivery.forceCheck',
                ], true);
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
