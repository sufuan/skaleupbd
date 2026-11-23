<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module access (M13 User & Role): a user without a module in their
 * effective permissions is bounced to the Dashboard, on top of the
 * sidebar already hiding the module. Prefix => module key.
 */
class ModuleAccess
{
    private const MAP = [        'orders' => 'orders',
        'delivery' => 'delivery',
        'inventory' => 'inventory',
        'messages' => 'messages',
        'comments' => 'messages',
        'customers' => 'customers',
        'finance' => 'finance',
        'hrm' => 'hrm',
        'settings' => 'settings',
        'billing' => 'billing',
        'purchase' => 'purchase',
        'purchases' => 'purchase',
        'suppliers' => 'purchase',
        'call-center' => 'callcenter',
        'tasks' => 'tasks',
        'follow-ups' => 'tasks',
        'work-inbox' => 'workinbox',
        'ads' => 'ads',
        'marketing' => 'marketing',
        'reports' => 'reports',
        'storefront' => 'storefront',
    ];

    /** Self-service attendance: any staff member can always open their own attendance page and check in/out, whatever their module permissions. */
    private const SELF_SERVICE = [
        'hrm/attendance', 'hrm/attendance/check-in', 'hrm/attendance/check-out', 'hrm/attendance/break', 'hrm/leaves',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->is_platform_admin || ! $user->business_id) {
            return $next($request);
        }

        if (in_array(trim($request->path(), '/'), self::SELF_SERVICE, true)) {
            return $next($request);
        }

        $first = explode('/', trim($request->path(), '/'))[0] ?? '';
        $module = self::MAP[$first] ?? null;
        if ($module && ! $user->canAccessModule($module)) {
            if ($request->expectsJson()) {
                abort(403, 'এই page দেখার permission নেই।');
            }

            return redirect('/dashboard')->with('error', 'এই page দেখার permission নেই।');
        }

        return $next($request);
    }
}
