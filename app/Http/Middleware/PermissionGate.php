<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Support\RouteRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission gate (ধাপ ২): enforces the RouteRegistry from the slot
 * ModuleAccess used to occupy — after HandleInertiaRequests, before
 * CheckInGate / TokenGate / SellerApprovalGate. Those three keep
 * their own behaviours (check-in, zero-balance, approval) untouched;
 * this gate decides permissions only, by route name, with the tick
 * the registry declares (view / edit / delete / export).
 *
 * Default-deny: a named route with no registry declaration is denied
 * (the completeness gate in the check chain makes that state
 * impossible to ship). Denial is a real 403 with the existing Bengali
 * message (gap G-H), not the old bounce to the dashboard; JSON
 * callers get the same 403 as JSON via the exception renderer.
 *
 * Pending sellers: a shop whose status is not approved belongs to
 * SellerApprovalGate. The registry therefore steps aside for that
 * shop's owner — in practice the only user a pending shop has, since
 * staff creation is itself behind the approval gate — so their
 * landing stays the status page exactly as before. Staff of any
 * shop, approved or not, are always enforced here.
 */
class PermissionGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($user->business_id && ! $user->isPlatformAdmin()
            && RouteRegistry::isOwnerLevel($user)
            && ! self::businessApproved((int) $user->business_id)) {
            return $next($request);
        }

        $name = $request->route()?->getName();
        if ($name === null || $name === '') {
            // Unnamed routes are framework surface (health check,
            // storage, sanctum). The registry governs named routes,
            // and the completeness gate keeps the two sets in step.
            return $next($request);
        }

        $declaration = RouteRegistry::for($name);
        if ($declaration !== null
            && ($declaration['context'] ?? null) === 'shop'
            && $user->business_id
            && ! $user->isPlatformAdmin()
            && ! RouteRegistry::isOwnerLevel($user)
            && $user->staff_active === false) {
            // A deactivated staff member can still sign in (their
            // credentials are unchanged), but every shop page is shut.
            // Say why, in their language, instead of the bare denial:
            // their access was switched off by their shop.
            abort(403, __('Your access has been switched off by your shop. Please ask the shop owner to turn it back on.'));
        }
        if ($declaration === null || ! RouteRegistry::allows($user, $declaration)) {
            abort(403, __('You do not have permission to see this page.'));
        }

        return $next($request);
    }

    /** Mirrors SellerApprovalGate: no status value counts as approved. */
    private static function businessApproved(int $businessId): bool
    {
        $status = Business::where('id', $businessId)->value('status');

        return $status === null || $status === '' || $status === 'approved';
    }
}
