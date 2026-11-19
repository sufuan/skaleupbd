<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'locale', 'totp_secret', 'totp_confirmed_at', 'business_id', 'is_admin', 'is_platform_admin', 'finance_access', 'role', 'permissions', 'monthly_salary', 'staff_active'])]
#[Hidden(['password', 'remember_token', 'totp_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            'is_admin' => 'boolean',
            'is_platform_admin' => 'boolean',
            'permissions' => 'array',
            'monthly_salary' => 'decimal:2',
            'staff_active' => 'boolean',
        ];
    }

    public function business(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Business::class);
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

    /** Module keys this user may open: explicit list wins, else the role preset. */
    public function effectivePermissions(): array
    {
        if ($this->is_platform_admin || $this->role === 'admin') {
            return \App\Support\Permissions::ALL;
        }
        if (is_array($this->permissions) && $this->permissions !== []) {
            return $this->permissions;
        }

        return \App\Support\Permissions::preset((string) $this->role);
    }

    public function canAccessModule(string $key): bool
    {
        return in_array($key, $this->effectivePermissions(), true);
    }
}
