<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Leads\Enums\ArchiveReason;
use App\Modules\Leads\Enums\ConversionMode;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Enums\LostReason;
use App\Modules\Leads\Enums\NextActionType;
use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;
use App\Modules\Units\Models\Unit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Lead extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'email',
        'source',
        'source_detail',
        'project_id',
        'unit_id',
        'stage',
        'assigned_to',
        'next_follow_up_at',
        'next_action_type',
        'next_action_note',
        'lost_reason',
        'lost_reason_detail',
        'lost_at',
        'lost_by',
        'customer_id',
        'converted_at',
        'converted_by',
        'conversion_mode',
        'archived_at',
        'archived_by',
        'archive_reason',
        'archive_reason_detail',
        'restored_by',
        'restored_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'stage' => LeadStage::class,
            'assigned_to' => 'integer',
            'next_follow_up_at' => 'datetime',
            'next_action_type' => NextActionType::class,
            'lost_reason' => LostReason::class,
            'lost_at' => 'datetime',
            'lost_by' => 'integer',
            'converted_at' => 'datetime',
            'converted_by' => 'integer',
            'conversion_mode' => ConversionMode::class,
            'archived_at' => 'datetime',
            'archived_by' => 'integer',
            'archive_reason' => ArchiveReason::class,
            'restored_by' => 'integer',
            'restored_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (TenantAdministratorAuthority::allows($user)) {
            return $query;
        }

        if (! in_array($user->role, [User::ROLE_SALES, User::ROLE_EMPLOYEE], true)) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereNull('archived_at')
            ->where(function (Builder $visibility) use ($user): void {
                $visibility->where('assigned_to', $user->id)
                    ->orWhere(function (Builder $unassigned): void {
                        $unassigned->whereNull('assigned_to')
                            ->whereIn('stage', LeadStage::openValues());
                    });
            });
    }

    public function scopeActiveOnly(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchivedOnly(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeOpenOnly(Builder $query): Builder
    {
        return $query->whereIn('stage', LeadStage::openValues());
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lostBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lost_by');
    }

    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function restorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function isOpen(): bool
    {
        return $this->stage->isOpen();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
