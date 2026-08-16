<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use LogicException;

final class Module extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'key',
        'name_ar',
        'name_en',
        'status',
    ];

    protected static function booted(): void
    {
        static::updating(function (Module $module): void {
            if ($module->isDirty('key')) {
                throw new LogicException('Commercial module keys are immutable.');
            }
        });
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_modules');
    }
}
