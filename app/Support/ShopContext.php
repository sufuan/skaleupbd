<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Http\Request;

/**
 * The one shop-scope resolver (ধাপ ৩, prep-map Option A).
 *
 * Tenant code never asks "whose business is this page about" from the
 * signed-in user directly any more: it asks here. Normally the answer
 * is the user's own business. When a platform admin is inside a shop
 * through view-as-seller, the answer is the VIEWED shop - while the
 * admin's identity (auth.user) never changes. Writes in that mode are
 * blocked separately by the ViewAsReadOnly middleware; this class
 * only resolves scope.
 *
 * View state lives in the session (never in URLs): the business id,
 * the admin who entered, and the absolute start time. The view lasts
 * 30 minutes from entry, NOT sliding - the ViewAsReadOnly middleware
 * enforces expiry (clears the keys, writes the audit row); this class
 * simply stops resolving an expired view.
 *
 * Identity surfaces deliberately do NOT use this resolver: Billing
 * (the owner's own wallet), Staff & Roles management, and anything
 * keyed to who the user IS rather than which shop is on screen.
 */
class ShopContext
{
    public const SESSION_BUSINESS = 'view_as.business_id';

    public const SESSION_STARTED = 'view_as.started_at';

    public const SESSION_ADMIN = 'view_as.admin_id';

    /** Absolute view lifetime in seconds (30 minutes, owner rule). */
    public const TTL = 1800;

    /**
     * The active view-as state for this request, or null: the signed-in
     * user is a platform admin, the session carries a view he started,
     * it has not expired, and the shop still exists.
     *
     * @return array{business_id: int, started_at: int, expires_at: int, seconds_left: int}|null
     */
    public static function viewState(Request $request): ?array
    {
        if ($request->attributes->has('shop_context.view')) {
            return $request->attributes->get('shop_context.view');
        }

        $state = null;
        $user = $request->user();
        if ($user && $user->isPlatformAdmin() && $request->hasSession()) {
            $session = $request->session();
            $businessId = (int) $session->get(self::SESSION_BUSINESS, 0);
            $adminId = (int) $session->get(self::SESSION_ADMIN, 0);
            $startedAt = (int) $session->get(self::SESSION_STARTED, 0);
            $now = now()->timestamp;
            if ($businessId > 0 && $adminId === (int) $user->id && $startedAt > 0
                && ($now - $startedAt) < self::TTL
                && Business::whereKey($businessId)->exists()) {
                $state = [
                    'business_id' => $businessId,
                    'started_at' => $startedAt,
                    'expires_at' => $startedAt + self::TTL,
                    'seconds_left' => max(0, $startedAt + self::TTL - $now),
                ];
            }
        }

        $request->attributes->set('shop_context.view', $state);

        return $state;
    }

    /** The viewed shop's id while view-as is active, else null. */
    public static function viewingAs(Request $request): ?int
    {
        return self::viewState($request)['business_id'] ?? null;
    }

    public static function isViewMode(Request $request): bool
    {
        return self::viewingAs($request) !== null;
    }

    /**
     * Raw session view (present but possibly expired or orphaned) -
     * the ViewAsReadOnly middleware uses this to detect expiry it
     * must clean up and audit.
     *
     * @return array{business_id: int, started_at: int}|null
     */
    public static function rawSessionView(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }
        $businessId = (int) $request->session()->get(self::SESSION_BUSINESS, 0);
        if ($businessId <= 0) {
            return null;
        }

        return [
            'business_id' => $businessId,
            'started_at' => (int) $request->session()->get(self::SESSION_STARTED, 0),
        ];
    }

    /** Drop the view keys (exit, expiry, admin mismatch). */
    public static function clear(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget([self::SESSION_BUSINESS, self::SESSION_STARTED, self::SESSION_ADMIN]);
        }
        $request->attributes->set('shop_context.view', null);
        $request->attributes->remove('shop_context.id');
    }

    /** True when the request runs in platform context: a platform admin NOT viewing a shop. */
    public static function isPlatformContext(Request $request): bool
    {
        $user = $request->user();

        return $user && $user->isPlatformAdmin() && ! self::isViewMode($request);
    }

    /**
     * The shop this request is about: the viewed shop in view mode,
     * otherwise the signed-in user's own business (0 when neither).
     */
    public static function id(Request $request): int
    {
        if ($request->attributes->has('shop_context.id')) {
            return (int) $request->attributes->get('shop_context.id');
        }

        $id = self::viewingAs($request) ?? (int) ($request->user()?->business_id ?? 0);
        $request->attributes->set('shop_context.id', $id);

        return $id;
    }
}
