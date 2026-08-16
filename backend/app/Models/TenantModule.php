<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TenantModule extends Model
{
    public const STATE_ENABLED = 'enabled';

    public const STATE_DISABLED = 'disabled';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'tenant_id',
        'module_id',
        'state',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
