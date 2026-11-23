<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'locale', 'totp_secret', 'totp_confirmed_at', 'business_id', 'is_platform_admin', 'is_owner', 'finance_access', 'role', 'role_id', 'permissions', 'monthly_salary', 'staff_active'])]
#[Hidden(['password', 'remember_token', 'totp_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Identity invariant (ধাপ ১): two kinds of users exist. A platform
     * admin (is_platform_admin) never belongs to a shop; a shop user
     * always belongs to one. Refuse a save that CREATES either invalid
     * combination, i.e. one where the guarded fields are being changed
     * into it. A legacy row that already violates the rule (the live
     * platform admin still carries business_id = 1 until the data fix
     * runs) keeps saving normally, so deploying this guard can never
     * break the app before that fix lands.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->is_platform_admin && $user->business_id !== null
                && ($user->isDirty('is_platform_admin') || $user->isDirty('business_id'))) {
                throw new \InvalidArgumentException('A platform admin cannot belong to a business.');
            }

            if (! $user->is_platform_admin && $user->business_id === null
                && $user->getOriginal('business_id') !== null && $user->isDirty('business_id')) {
                throw new \InvalidArgumentException('A shop user cannot be separated from their business.');
            }
        });
    }

    /** The platform concept, spelled once. */
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    /** The seller-owner concept: belongs to a shop and is marked its owner. */
    public function isShopOwner(): bool
    {
        return $this->business_id !== null && (bool) $this->is_owner;
    }

    public function scopePlatformAdmins(Builder $query): Builder
    {
        return $query->where('is_platform_admin', true);
    }

    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    /**
     * The user who stands in for a shop when the system itself must act
     * (courier webhook lifecycle changes, automated jobs): the shop's
     * owner. Falls back to the shop's role-admin user for any shop whose
     * owner row predates the is_owner marker and was not backfilled.
     */
    public static function shopOwner(int $businessId): ?User
    {
        return static::forBusiness($businessId)->where('is_owner', true)->oldest('id')->first()
            ?? static::forBusiness($businessId)->where('role', 'admin')->oldest('id')->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'is_owner' => 'boolean',
            'permissions' => 'array',
            'monthly_salary' => 'decimal:2',
            'staff_active' => 'boolean',
        ];
    }

    public function business(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** The seller-defined staff role this user belongs to (ধাপ ৪). */
    public function staffRole(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /** Send the branded SkaleUp BD password reset notification. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }

    /** True once the user has enrolled in TOTP and confirmed a code. */
    public function hasTotpEnabled(): bool
    {
        return ! empty($this->totp_secret) && $this->totp_confirmed_at !== null;
    }

    public function departments(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_members')->withTimestamps();
    }

    public function department(): ?Department
    {
        return $this->departments()->first();
    }

    public function shifts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Shift::class, 'shift_assignments')->withTimestamps();
    }

    public function shift(): ?Shift
    {
        return $this->shifts()->first();
    }

    public function attendanceRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * Module keys this user may open. A seller-defined staff role
     * (ধাপ ৪) wins: its view ticks become the module list. Before a
     * role is assigned, the legacy rules apply: explicit permission
     * list wins, else the role preset. Read fresh from the database
     * on every call, so a role change takes effect immediately.
     */
    public function effectivePermissions(): array
    {
        if ($this->isPlatformAdmin() || $this->isShopOwner()) {
            return \App\Support\Permissions::ALL;
        }
        if ($this->role_id) {
            $role = $this->staffRole;

            return $role
                ? \App\Support\RolePermissions::viewModules($role->permissions)
                : ['dashboard'];
        }
        if (is_array($this->permissions) && $this->permissions !== []) {
            return $this->permissions;
        }

        return \App\Support\Permissions::preset((string) $this->role);
    }

    /** The four-tick map (view/edit/delete/export per module) this user holds. */
    public function permissionTicks(): array
    {
        return \App\Support\RolePermissions::ticksFor($this);
    }

    public function canAccessModule(string $key): bool
    {
        return in_array($key, $this->effectivePermissions(), true);
    }
}
