<?php

declare(strict_types=1);

namespace App\Modules\Leads\Models;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Exceptions\LeadActivityIsAppendOnlyException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LeadActivity extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'type',
        'body',
        'payload',
        'occurred_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LeadActivityIsAppendOnlyException();
        });

        static::deleting(static function (): never {
            throw new LeadActivityIsAppendOnlyException();
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
