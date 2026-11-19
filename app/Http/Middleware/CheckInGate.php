<?php

namespace App\Http\Middleware;

use App\Services\AttendanceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Require check-in to work" (M13 HR Settings, default OFF): while the
 * switch is on, a non-admin staff member who has not checked in today is
 * held on the Attendance page. Admins are never held.
 */
class CheckInGate
{
    private const EXEMPT_PREFIXES = ['hrm', 'logout', 'profile', 'sidebar-counts', 'notifications', 'locale', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->business_id || $user->is_platform_admin || $user->role === 'admin') {
            return $next($request);
        }
        // The attendance page itself is self-service (ModuleAccess always lets
        // it through), so a held user can always reach it and check in.

        $first = explode('/', trim($request->path(), '/'))[0] ?? '';
        if (in_array($first, self::EXEMPT_PREFIXES, true) || $first === '') {
            return $next($request);
        }

        $svc = app(AttendanceService::class);
        if (! $svc->setting((int) $user->business_id, 'require_checkin', false)) {
            return $next($request);
        }

        $record = $svc->recordFor($user, $svc->todayDate());
        if ($record && $record->check_in_at) {
            return $next($request);
        }
        if ($record && $record->status === 'leave') {
            return $next($request);
        }

        return redirect('/hrm/attendance')->with('error', 'আজ এখনো Check-in হয়নি — আগে Check-in করুন, তারপর বাকি কাজ খুলবে।');
    }
}
