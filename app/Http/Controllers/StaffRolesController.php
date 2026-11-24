<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\RoleChangeLog;
use App\Models\User;
use App\Support\Permissions;
use App\Support\RolePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Settings → Staff & Roles (ধাপ ৪). The single home for staff
 * logins and their permissions; HRM's old User & Role page moved
 * here and was removed there.
 *
 * Hard rules, all enforced here on the server:
 *  - Only the shop OWNER (role = 'admin' inside the business) may
 *    manage staff and roles. This power is never delegable: no
 *    staff role, however ticked, opens these endpoints.
 *  - business_id always comes from the signed-in user, never from
 *    the request body.
 *  - Owner-only modules (billing, settings) can never be saved
 *    into a role (RolePermissions::normalize strips them, and a
 *    request that tries is refused outright).
 *  - Nobody grants a tick they do not hold themselves.
 *  - The owner's own access cannot be edited through here, and
 *    the last owner of a shop can never be deactivated.
 *  - Deactivating a staff member deletes their sessions at once.
 */
class StaffRolesController extends Controller
{
    private function biz(Request $request): int
    {
        return (int) $request->user()->business_id;
    }

    /** Staff & Roles management is the owner's alone. */
    private function canManage(Request $request): bool
    {
        $u = $request->user();

        return $u && $u->business_id && $u->role === 'admin';
    }

    private function deny(): RedirectResponse
    {
        return back()->with('error', __('You do not have permission for this action. Only the shop Owner can do this.'));
    }

    /** Props for the Staff & Roles group inside Settings. */
    public function pageData(Request $request): ?array
    {
        $user = $request->user();
        if (! $user || ! $user->business_id) {
            return null;
        }
        $biz = (int) $user->business_id;
        $canManage = $this->canManage($request);

        $roles = Role::where('business_id', $biz)->orderBy('name')->withCount('users')->get()
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'ticks' => RolePermissions::normalize($r->permissions),
                'staff_count' => $r->users_count,
            ])->all();

        $staff = User::where('business_id', $biz)->orderBy('name')->with('staffRole')->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_owner' => $u->role === 'admin',
                'role_id' => $u->role_id,
                'role_name' => $u->role === 'admin'
                    ? 'Owner'
                    : ($u->staffRole?->name ?? Permissions::roles()[(string) $u->role] ?? (string) $u->role),
                'view_modules' => $u->effectivePermissions(),
                'monthly_salary' => (float) $u->monthly_salary,
                'staff_active' => (bool) $u->staff_active,
                'is_self' => $u->id === $user->id,
            ])->all();

        return [
            'can_manage' => $canManage,
            'roles' => $roles,
            'staff' => $staff,
            'modules' => collect(RolePermissions::modules())->map(fn (string $key) => [
                'key' => $key,
                'label' => Permissions::LABELS[$key] ?? $key,
                'owner_only' => RolePermissions::isOwnerOnly($key),
            ])->all(),
            'actions' => RolePermissions::ACTIONS,
        ];
    }

    // ---------- Roles ----------

    public function storeRole(Request $request): RedirectResponse
    {
        if (! $this->canManage($request)) {
            return $this->deny();
        }
        $biz = $this->biz($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('roles', 'name')->where('business_id', $biz)],
            'ticks' => 'nullable|array',
        ]);
        if ($refusal = $this->tickRefusal($request, $data['ticks'] ?? [])) {
            return $refusal;
        }
        $role = Role::create([
            'business_id' => $biz,
            'name' => $data['name'],
            'permissions' => RolePermissions::normalize($data['ticks'] ?? []),
        ]);
        RoleChangeLog::record($biz, $request->user()->id, 'role.created', [
            'role_id' => $role->id, 'name' => $role->name, 'ticks' => $role->permissions,
        ]);

        return back()->with('success', __('New role created.'));
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        if (! $this->canManage($request) || $role->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        $biz = $this->biz($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('roles', 'name')->where('business_id', $biz)->ignore($role->id)],
            'ticks' => 'nullable|array',
        ]);
        if ($refusal = $this->tickRefusal($request, $data['ticks'] ?? [])) {
            return $refusal;
        }
        $old = ['name' => $role->name, 'ticks' => RolePermissions::normalize($role->permissions)];
        $role->name = $data['name'];
        $role->permissions = RolePermissions::normalize($data['ticks'] ?? []);
        $role->save();
        RoleChangeLog::record($biz, $request->user()->id, 'role.updated', [
            'role_id' => $role->id, 'old' => $old,
            'new' => ['name' => $role->name, 'ticks' => $role->permissions],
        ]);

        return back()->with('success', __('Role updated.'));
    }

    public function destroyRole(Request $request, Role $role): RedirectResponse
    {
        if (! $this->canManage($request) || $role->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        if ($role->users()->exists()) {
            return back()->with('error', __('This role still has staff. Give them another role first, then delete it.'));
        }
        RoleChangeLog::record($this->biz($request), $request->user()->id, 'role.deleted', [
            'role_id' => $role->id, 'name' => $role->name,
        ]);
        $role->delete();

        return back()->with('success', __('Role deleted.'));
    }

    /**
     * Refuse a tick set that reaches for owner-only modules or for
     * ticks the actor does not hold. Returns null when it is clean.
     */
    private function tickRefusal(Request $request, array $ticks): ?RedirectResponse
    {
        foreach (RolePermissions::OWNER_ONLY as $module) {
            foreach (RolePermissions::ACTIONS as $action) {
                if (! empty($ticks[$module][$action])) {
                    return back()->with('error', __('Points & Billing and Settings stay with the Owner only - they cannot be given to any role.'));
                }
            }
        }
        $exceeds = RolePermissions::exceedsGrantable($request->user(), $ticks);
        if ($exceeds !== []) {
            return back()->with('error', __('You cannot give a permission you do not have yourself.'));
        }

        return null;
    }

    // ---------- Staff ----------

    /** Moved from HRM User & Role (storeUser) and extended with role assignment. */
    public function storeStaff(Request $request): RedirectResponse
    {
        if (! $this->canManage($request)) {
            return $this->deny();
        }
        $biz = $this->biz($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:users,email',
            'password' => 'required|string|min:8',
            'role_id' => ['nullable', Rule::exists('roles', 'id')->where('business_id', $biz)],
            'monthly_salary' => 'nullable|numeric|min:0',
        ]);
        $role = ! empty($data['role_id']) ? Role::find($data['role_id']) : null;
        $user = User::create([
            'business_id' => $biz,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'staff',
            'role_id' => $role?->id,
            'monthly_salary' => $data['monthly_salary'] ?? 0,
            'email_verified_at' => now(),
        ]);
        $user->finance_access = $this->financeAccessFor($role);
        $user->save();
        RoleChangeLog::record($biz, $request->user()->id, 'staff.created', [
            'user_id' => $user->id, 'email' => $user->email, 'role_id' => $role?->id, 'role_name' => $role?->name,
        ]);

        return back()->with('success', __('New staff created.'));
    }

    /** Moved from HRM User & Role (updateUser) and rebuilt around role assignment. */
    public function updateStaff(Request $request, User $user): RedirectResponse
    {
        if (! $this->canManage($request) || $user->business_id !== $this->biz($request)) {
            return $this->deny();
        }
        $biz = $this->biz($request);
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'role_id' => ['nullable', Rule::exists('roles', 'id')->where('business_id', $biz)],
            'monthly_salary' => 'nullable|numeric|min:0',
            'staff_active' => 'boolean',
            // Same rule as staff creation, but optional here: blank
            // keeps the current password.
            'password' => 'nullable|string|min:8',
        ]);
        $active = $data['staff_active'] ?? true;

        if ($user->role === 'admin') {
            // The owner's access is never edited from here: no role
            // assignment, ever. Deactivation is refused for oneself
            // and for the shop's last active owner; name and salary
            // stay editable.
            if (! empty($data['role_id'])) {
                return back()->with('error', __('The Owner\'s role cannot be changed.'));
            }
            if (! $active && $user->id === $request->user()->id) {
                return back()->with('error', __('You cannot deactivate yourself.'));
            }
            if (! $active && $this->isLastActiveOwner($user)) {
                return back()->with('error', __('The shop\'s last Owner cannot be deactivated.'));
            }
        }

        $oldRoleId = $user->role_id;
        $user->name = $data['name'];
        $user->monthly_salary = $data['monthly_salary'] ?? 0;
        $user->staff_active = $active;
        $passwordChanged = false;
        if ($user->role !== 'admin') {
            $role = ! empty($data['role_id']) ? Role::find($data['role_id']) : null;
            $user->role_id = $role?->id;
            $user->permissions = null;
            $user->finance_access = $this->financeAccessFor($role);
            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
                $passwordChanged = true;
            }
        }
        $user->save();

        if (! $active || $passwordChanged) {
            // Deactivation logs the staff member out everywhere, now.
            // A password reset does the same: every existing session
            // must sign in again with the new password.
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        RoleChangeLog::record($biz, $request->user()->id, 'staff.updated', [
            'user_id' => $user->id,
            'old_role_id' => $oldRoleId,
            'new_role_id' => $user->role_id,
            'staff_active' => (bool) $user->staff_active,
            'password_changed' => $passwordChanged,
        ]);

        return back()->with('success', __('Staff updated.'));
    }

    /** True when deactivating this user would leave the shop with no active owner. */
    private function isLastActiveOwner(User $user): bool
    {
        if ($user->role !== 'admin') {
            return false;
        }

        return User::where('business_id', $user->business_id)
            ->where('role', 'admin')
            ->where('staff_active', true)
            ->whereKeyNot($user->id)
            ->doesntExist();
    }

    /** Finance visibility follows the role's finance ticks. */
    private function financeAccessFor(?Role $role): string
    {
        $ticks = $role ? RolePermissions::normalize($role->permissions) : [];

        return ! empty($ticks['finance']['view']) && ! empty($ticks['finance']['edit']) ? 'full' : 'entry';
    }
}
