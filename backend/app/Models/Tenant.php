<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;
    use HasUlids;

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'timezone',
        'locale',
        'currency',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(TenantLicense::class);
    }

    public function moduleOverrides(): HasMany
    {
        return $this->hasMany(TenantModule::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
