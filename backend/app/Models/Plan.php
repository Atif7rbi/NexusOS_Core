<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Plan extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'key',
        'name_ar',
        'name_en',
        'status',
        'users_limit',
    ];

    protected function casts(): array
    {
        return [
            'users_limit' => 'integer',
        ];
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'plan_modules');
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(TenantLicense::class);
    }
}
