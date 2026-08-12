<?php

namespace App\Models;

use App\Modules\Shared\Authorization\TenantAdministratorAuthority;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    public const ROLE_SYSTEM_OWNER = 'system_owner';

    public const ROLE_ADMINISTRATOR = 'administrator';

    public const ROLE_PROJECT_MANAGER = 'project_manager';

    public const ROLE_SALES = 'sales';

    public const ROLE_ACCOUNTANT = 'accountant';

    public const ROLE_EMPLOYEE = 'employee';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'name',
        'email',
        'role',
        'status',
        'phone',
        'password',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSystemOwner(): bool
    {
        return $this->role === self::ROLE_SYSTEM_OWNER;
    }

    public function isAdministrator(): bool
    {
        return TenantAdministratorAuthority::allows($this);
    }

    public function canManageUsers(): bool
    {
        return TenantAdministratorAuthority::allows($this);
    }

    public function tenantMemberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function activeTenantMemberships(): HasMany
    {
        return $this->tenantMemberships()
            ->where(
                'status',
                TenantUser::STATUS_ACTIVE
            );
    }
}
