<?php

namespace App\Http\Middleware;

use App\Models\PlatformViewLog;
use App\Support\ShopContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The read-only guard for view-as-seller (ধাপ ৩). Sits in the web
 * group AFTER PermissionGate and BEFORE TokenGate: permissions are
 * decided first (a platform admin passes them by design), then this
 * guard decides whether the request may WRITE while a view is open.
 *
 * Rules (prep map section 4.3):
 *  - No view session, or the user is not the platform admin who
 *    started it: pass untouched (a foreign admin id clears the keys).
 *  - Expired view (30 minutes, absolute): clear the keys, write the
 *    "expired" audit row, and end the request - GETs redirect to the
 *    sellers list with a flash, writes are refused 403. A stale write
 *    is never let through under an expired view.
 *  - Active view + GET/HEAD/OPTIONS: pass.
 *  - Active view + any other method: blocked with 403 and EVERY
 *    attempt is audit-logged - except the small exemption list below
 *    (the exit itself, entering another shop, logout, locale, and
 *    the shell's own housekeeping POSTs that only touch the ADMIN's
 *    own rows: the sidebar seen-watermark and notification mark-read,
 *    whose data stays scoped to the admin by visibleTo()).
 *
 * The gate interplay is inherited: TokenGate, SellerApprovalGate and
 * CheckInGate all skip platform admins, so a zero-balance or pending
 * shop stays viewable, exactly as a support view needs.
 */
class ViewAsReadOnly
{
    /** Route names that stay allowed while a view is open. */
    private const EXEMPT = [
        'platform.viewAs.enter',
        'platform.viewAs.exit',
        'logout',
        'locale.switch',
        'sidebar.counts.seen',
        'notifications.read',
        'notifications.readAll',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $request->hasSession()) {
            return $next($request);
        }

        $raw = ShopContext::rawSessionView($request);
        if ($raw === null) {
            return $next($request);
        }

        if (! $user->isPlatformAdmin()
            || (int) $request->session()->get(ShopContext::SESSION_ADMIN, 0) !== (int) $user->id) {
            // Not this user's view to carry (or not a platform admin
            // at all): drop the keys and carry on as himself.
            ShopContext::clear($request);

            return $next($request);
        }

        $expired = $raw['started_at'] <= 0
            || (now()->timestamp - $raw['started_at']) >= ShopContext::TTL;
        if ($expired) {
            PlatformViewLog::record((int) $user->id, $raw['business_id'], 'expired', $request, [
                'shop_name' => \App\Models\Business::whereKey($raw['business_id'])->value('name'),
            ]);
            ShopContext::clear($request);
            if (in_array($request->method(), ['GET', 'HEAD'], true)) {
                return redirect('/admin/sellers')
                    ->with('error', 'The read-only view ended after 30 minutes.');
            }
            abort(403, 'The read-only view ended after 30 minutes. Nothing was changed.');
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $name = $request->route()?->getName();
        if ($name !== null && in_array($name, self::EXEMPT, true)) {
            return $next($request);
        }

        PlatformViewLog::record((int) $user->id, $raw['business_id'], 'write_blocked', $request, [
            'route' => $request->method().' /'.ltrim($request->path(), '/'),
            'shop_name' => \App\Models\Business::whereKey($raw['business_id'])->value('name'),
        ]);
        abort(403, 'You are viewing this shop read-only. Exit the view to make changes.');
    }
}
