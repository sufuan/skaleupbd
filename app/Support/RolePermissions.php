<?php

namespace App\Support;

use App\Models\User;

/**
 * The Staff & Roles permission catalogue (ধাপ ৪).
 *
 * The platform defines the catalogue; a seller only ticks boxes. Every
 * module key carries FOUR independent ticks: view, edit (create and
 * update), delete, export. The "view" tick is what the older module
 * system understands: it maps 1:1 onto User::effectivePermissions(),
 * so a role with orders.view can open the Order List exactly like
 * the old permission list allowed. Since ধাপ ২ the RouteRegistry +
 * PermissionGate enforce all four ticks server-side, per route.
 *
 * Owner-only, NON-delegable areas never appear as grantable ticks:
 *  - billing  (Points & Billing)
 *  - settings (the Settings area holds Security & Records and the
 *              API Connection keys; the 18-key catalogue has no
 *              sub-settings granularity, so the whole key stays
 *              with the owner)
 * Staff & Roles management itself is not a tick at all: the settings
 * endpoints that write roles and staff refuse everyone but the
 * shop owner (role = 'admin' inside the business).
 */
class RolePermissions
{
    /** The four ticks every module key carries, in display order. */
    public const ACTIONS = ['view', 'edit', 'delete', 'export'];

    /** Module keys the owner can never hand to a staff role. */
    public const OWNER_ONLY = ['billing', 'settings'];

    /** Every module key in the catalogue (the existing 18). */
    public static function modules(): array
    {
        return Permissions::ALL;
    }

    /** Module keys a staff role may be granted. */
    public static function grantableModules(): array
    {
        return array_values(array_diff(self::modules(), self::OWNER_ONLY));
    }

    public static function isOwnerOnly(string $module): bool
    {
        return in_array($module, self::OWNER_ONLY, true);
    }

    /** An empty tick map: every module, every action off. */
    public static function blank(): array
    {
        $out = [];
        foreach (self::modules() as $module) {
            $out[$module] = array_fill_keys(self::ACTIONS, false);
        }

        return $out;
    }

    /** Full tick map (owner / platform admin): everything on. */
    public static function full(): array
    {
        $out = [];
        foreach (self::modules() as $module) {
            $out[$module] = array_fill_keys(self::ACTIONS, true);
        }

        return $out;
    }

    /**
     * Clean an untrusted tick map into the stored shape: grantable
     * modules only, the four known actions only, strict booleans.
     * Owner-only modules are stripped here, so a forged request can
     * never smuggle billing/settings into a role.
     */
    public static function normalize(mixed $input): array
    {
        $out = [];
        if (! is_array($input)) {
            return $out;
        }
        foreach (self::grantableModules() as $module) {
            $row = $input[$module] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $clean = [];
            foreach (self::ACTIONS as $action) {
                $clean[$action] = filter_var($row[$action] ?? false, FILTER_VALIDATE_BOOLEAN);
            }
            if (in_array(true, $clean, true)) {
                $out[$module] = $clean;
            }
        }

        return $out;
    }

    /** Module keys whose view tick is on - the old-style module list. */
    public static function viewModules(mixed $ticks): array
    {
        if (! is_array($ticks)) {
            return [];
        }
        $out = [];
        foreach (self::grantableModules() as $module) {
            if (! empty($ticks[$module]['view'])) {
                $out[] = $module;
            }
        }

        return $out;
    }

    /**
     * Build a tick map from an old-style flat module list (the legacy
     * users.permissions / preset world). Legacy lists only ever
     * controlled visibility, so they map to the view tick only.
     */
    public static function fromModuleList(array $modules): array
    {
        $out = [];
        foreach (self::grantableModules() as $module) {
            if (in_array($module, $modules, true)) {
                $out[$module] = ['view' => true, 'edit' => false, 'delete' => false, 'export' => false];
            }
        }

        return $out;
    }

    /** The tick map a user actually holds right now. */
    public static function ticksFor(User $user): array
    {
        if ($user->is_platform_admin || $user->role === 'admin') {
            return self::full();
        }
        $role = $user->staffRole;
        if ($role) {
            return self::normalize($role->permissions);
        }

        return self::fromModuleList($user->effectivePermissions());
    }

    /** Ticks the actor may hand out: never owner-only, never more than held. */
    public static function grantableTicksFor(User $actor): array
    {
        $held = self::ticksFor($actor);
        foreach (self::OWNER_ONLY as $module) {
            unset($held[$module]);
        }

        return $held;
    }

    /**
     * Rule (b): nobody grants a tick they do not hold themselves.
     * Returns the list of offending "module.action" strings (empty = ok).
     */
    public static function exceedsGrantable(User $actor, array $ticks): array
    {
        $grantable = self::grantableTicksFor($actor);
        $bad = [];
        foreach (self::normalize($ticks) as $module => $row) {
            foreach (self::ACTIONS as $action) {
                if (! empty($row[$action]) && empty($grantable[$module][$action])) {
                    $bad[] = $module . '.' . $action;
                }
            }
        }

        return $bad;
    }
}
