<?php

declare(strict_types=1);

namespace App\Modules\Shared\Time;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class TenantClock
{
    public const DEFAULT_TIMEZONE = 'Asia/Riyadh';

    public function timezone(string $tenantId): string
    {
        $timezone = Tenant::query()
            ->whereKey($tenantId)
            ->value('timezone');

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : self::DEFAULT_TIMEZONE;
    }

    public function now(string $tenantId): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone($tenantId));
    }

    public function today(string $tenantId): CarbonImmutable
    {
        return $this->now($tenantId)->startOfDay();
    }

    public function parse(
        string $tenantId,
        string|DateTimeInterface $value,
    ): CarbonImmutable {
        $date = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse(
                $value,
                $this->timezone($tenantId),
            );

        return $date->utc();
    }
}
