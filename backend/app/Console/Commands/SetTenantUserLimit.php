<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Entitlements\Services\SetTenantUserLimitOverride;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

final class SetTenantUserLimit extends Command
{
    protected $signature = 'nexusos:set-user-limit
        {--tenant= : Exact Tenant ULID or slug}
        {--limit= : Positive seat limit override}
        {--inherit : Clear the override and inherit the Plan limit}';

    protected $description = 'Set or clear the current Tenant License user-seat override';

    public function __construct(
        private readonly SetTenantUserLimitOverride $setter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $tenantReference = trim((string) $this->option('tenant'));

            if ($tenantReference === '') {
                throw new DomainException('--tenant is required.');
            }

            $tenant = Tenant::query()
                ->whereKey($tenantReference)
                ->orWhere('slug', $tenantReference)
                ->first();

            if ($tenant === null) {
                throw new DomainException("Tenant [{$tenantReference}] was not found.");
            }

            $inherit = (bool) $this->option('inherit');
            $rawLimit = trim((string) $this->option('limit'));

            if ($inherit && $rawLimit !== '') {
                throw new DomainException('Use either --limit or --inherit, not both.');
            }

            if (! $inherit && $rawLimit === '') {
                throw new DomainException('Supply --limit or --inherit.');
            }

            $limit = $inherit ? null : $this->parsePositiveInteger($rawLimit);
            $license = $this->setter->handle((string) $tenant->id, $limit);
            $effective = $license->users_limit_override ?? $license->plan->users_limit;

            $this->info('Tenant user-seat policy updated successfully.');
            $this->line('Tenant: '.$tenant->id);
            $this->line('License: '.$license->id);
            $this->line('Override: '.($license->users_limit_override ?? 'inherit'));
            $this->line('Effective limit: '.($effective ?? 'unlimited'));

            return self::SUCCESS;
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Seat policy update failed. Review the application log.');

            return self::FAILURE;
        }
    }

    private function parsePositiveInteger(string $value): int
    {
        if (! preg_match('/^[1-9]\d*$/', $value)) {
            throw new DomainException('--limit must be a positive integer.');
        }

        $limit = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 4294967295,
            ],
        ]);

        if ($limit === false) {
            throw new DomainException('--limit is outside the supported integer range.');
        }

        return (int) $limit;
    }
}
