<?php

declare(strict_types=1);

namespace App\Modules\Units\Models;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Units\Enums\UnitStatus;
use App\Modules\Units\Enums\UnitType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Unit extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'unit_number',
        'unit_type',
        'status',
        'selling_price',
        'area',
        'floor',
        'bedrooms',
        'bathrooms',
        'notes',
        'created_by',
        'updated_by',
        'archived_by',
        'restored_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'unit_type' => UnitType::class,
            'status' => UnitStatus::class,
            'selling_price' => 'decimal:2',
            'area' => 'decimal:2',
            'floor' => 'integer',
            'bedrooms' => 'integer',
            'bathrooms' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function restorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
