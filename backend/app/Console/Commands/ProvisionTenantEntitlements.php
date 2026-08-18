<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Entitlements\Services\ProvisionPilotEntitlements;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

final class ProvisionTenantEntitlements extends Command
{
    protected $signature = 'nexusos:provision-entitlements
        {--tenant= : Exact Tenant ULID or slug}
        {--plan=pilot_full : Approved Plan key}
        {--status=active : License status}
        {--starts-at= : Required ISO-8601 timestamp with timezone}
        {--ends-at= : Required ISO-8601 timestamp with timezone}
        {--grace-ends-at= : ISO-8601 timestamp with timezone; grace only}
        {--users-limit-override= : Optional positive per-license user-seat limit}';

    protected $description = 'Provision the approved NexusOS module catalog, plan, and Tenant license';

    public function __construct(
        private readonly ProvisionPilotEntitlements $provisioner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $tenant = trim((string) $this->option('tenant'));
            $startsAt = $this->parseRequiredTimestamp('starts-at');
            $endsAt = $this->parseRequiredTimestamp('ends-at');
            $graceEndsAt = $this->parseOptionalTimestamp('grace-ends-at');
            $usersLimitOverride = $this->parseOptionalPositiveInteger('users-limit-override');

            if ($tenant === '') {
                throw new DomainException('--tenant is required.');
            }

            $result = $this->provisioner->handle(
                tenantReference: $tenant,
                planKey: trim((string) $this->option('plan')),
                status: trim((string) $this->option('status')),
                startsAt: $startsAt,
                endsAt: $endsAt,
                graceEndsAt: $graceEndsAt,
                usersLimitOverride: $usersLimitOverride,
            );

            $this->info($result['created']
                ? 'Tenant entitlements provisioned successfully.'
                : 'Identical Tenant entitlements already exist; verification succeeded.');
            $this->line('Tenant: '.$result['tenant']->id);
            $this->line('Plan: '.$result['plan']->key);
            $this->line('License: '.$result['license']->id);
            $this->line('Effective modules: '.implode(', ', $result['effective_modules']));
            $this->line('Users limit override: '.($result['license']->users_limit_override ?? 'inherit'));

            return self::SUCCESS;
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Entitlement provisioning failed. Review the application log.');

            return self::FAILURE;
        }
    }

    private function parseRequiredTimestamp(string $option): CarbonImmutable
    {
        $value = trim((string) $this->option($option));

        if ($value === '') {
            throw new DomainException("--{$option} is required.");
        }

        return $this->parseTimestamp($option, $value);
    }

    private function parseOptionalTimestamp(string $option): ?CarbonImmutable
    {
        $value = trim((string) $this->option($option));

        return $value === '' ? null : $this->parseTimestamp($option, $value);
    }

    private function parseOptionalPositiveInteger(string $option): ?int
    {
        $value = trim((string) $this->option($option));

        if ($value === '') {
            return null;
        }

        if (! preg_match('/^[1-9]\d*$/', $value)) {
            throw new DomainException("--{$option} must be a positive integer.");
        }

        return (int) $value;
    }

    private function parseTimestamp(string $option, string $value): CarbonImmutable
    {
        if (! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/i', $value)) {
            throw new DomainException(
                "--{$option} must be an ISO-8601 timestamp with an explicit timezone.",
            );
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new DomainException("--{$option} is not a valid timestamp.");
        }
    }
}
