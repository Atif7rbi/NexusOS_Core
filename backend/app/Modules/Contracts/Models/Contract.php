<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Models;

use App\Models\User;
use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Reservations\Models\Reservation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class Contract extends Model
{
    protected $table = 'contracts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (Contract $contract): void {
            if (! $contract->getKey()) {
                $contract->id = (string) Str::ulid();
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'reservation_id',
        'status',
        'total_amount',
        'activated_at',
        'completed_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'total_amount' => 'decimal:2',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
