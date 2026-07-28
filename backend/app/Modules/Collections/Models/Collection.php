<?php

declare(strict_types=1);

namespace App\Modules\Collections\Models;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Contracts\Models\Contract;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Collection DDL Specification v1, sections 9-11 (FROZEN).
 *
 * This model reflects the frozen schema exactly. It does not introduce any
 * column, relationship, or cast that is not defined in the specification.
 * The `collections` table itself is created by the `feature/collections-ddl`
 * branch; this class assumes that schema exists.
 */
final class Collection extends Model
{
    use HasUlids;

    protected $table = 'collections';

    protected $fillable = [
        'tenant_id',
        'contract_id',
        'sequence',
        'title',
        'amount',
        'due_date',
        'notes',
        'status',
        'scheduled_at',
        'scheduled_by',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'status' => CollectionStatus::class,
            'scheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isActive(): bool
    {
        return $this->status !== CollectionStatus::Cancelled;
    }
}
