<?php

namespace App\Modules\Projects\Models;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Enums\ProjectType;
use App\Modules\Shared\Enums\DataOrigin;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'project_number',
        'project_number_year',
        'project_sequence_number',
        'name',
        'description',
        'project_type',
        'status',
        'country_code',
        'city',
        'district',
        'address_line',
        'currency',
        'estimated_budget',
        'planned_start_date',
        'planned_end_date',
        'actual_start_date',
        'actual_end_date',
        'project_manager_id',
        'data_origin',
        'external_reference',
        'legacy_reference',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'project_type' => ProjectType::class,
            'status' => ProjectStatus::class,
            'data_origin' => DataOrigin::class,
            'estimated_budget' => 'decimal:2',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'actual_start_date' => 'date',
            'actual_end_date' => 'date',
            'archived_at' => 'datetime',
            'project_number_year' => 'integer',
            'project_sequence_number' => 'integer',
        ];
    }

    public function projectManager(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'project_manager_id',
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by',
        );
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by',
        );
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
