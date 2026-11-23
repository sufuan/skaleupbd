<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use App\Support\RolePermissions;

/**
 * Day-one migration for Staff & Roles (ধাপ ৪): for one shop, turn
 * every existing staff member's CURRENT effective module list into
 * a role and assign it, so nobody's access changes when the role
 * system switches on.
 *
 * Grouping: staff who today resolve to the same module list share
 * one auto-created role. Its name comes from the old preset they
 * were running on ("Old - Manager", "Old - Accountant"); staff with
 * a hand-set list get "Old - Custom" (numbered when several
 * different custom lists exist in the same shop).
 *
 * Owner-only modules (billing, settings) are NOT carried into staff
 * roles: the owner's rule makes them non-delegable, so a staff list
 * that contained them loses exactly those two modules and nothing
 * else. The legacy users.permissions column is cleared once the
 * role is assigned, so the role becomes the single source.
 *
 * Idempotent: a user who already has a role is skipped, and an
 * existing same-name role with the same ticks is reused.
 */
class StaffRoleBackfill
{
    /** @return array{roles: int, assigned: int} counts for this shop */
    public function forBusiness(int $businessId): array
    {
        $staff = User::where('business_id', $businessId)
            ->where('is_platform_admin', false)
            ->where(fn ($q) => $q->whereNull('role')->orWhere('role', '!=', 'admin'))
            ->whereNull('role_id')
            ->orderBy('id')
            ->get();

        $roles = 0;
        $assigned = 0;
        /** @var array<string, Role> $bySignature */
        $bySignature = [];

        foreach ($staff as $user) {
            $modules = $user->effectivePermissions();
            $ticks = RolePermissions::fromModuleList($modules);
            $signature = md5(json_encode($ticks));

            if (! isset($bySignature[$signature])) {
                $bySignature[$signature] = $this->findOrCreateRole($businessId, $user, $ticks, $roles);
            }
            $role = $bySignature[$signature];

            $user->forceFill(['role_id' => $role->id, 'permissions' => null])->save();
            $assigned++;
        }

        return ['roles' => $roles, 'assigned' => $assigned];
    }

    private function findOrCreateRole(int $businessId, User $sample, array $ticks, int &$created): Role
    {
        $usesDefault = ! is_array($sample->permissions) || $sample->permissions === [];
        $presetLabel = Permissions::roles()[(string) $sample->role] ?? null;
        $base = 'Old - ' . ($usesDefault && $presetLabel ? $presetLabel : 'Custom');

        $name = $base;
        $n = 1;
        while (true) {
            $existing = Role::where('business_id', $businessId)->where('name', $name)->first();
            if (! $existing) {
                $created++;

                return Role::create([
                    'business_id' => $businessId,
                    'name' => $name,
                    'permissions' => $ticks,
                ]);
            }
            if (RolePermissions::normalize($existing->permissions) == $ticks) {
                return $existing;
            }
            $name = $base . ' ' . (++$n);
        }
    }
}
