<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Support\AccountingAuditWriter;
use App\Modules\Accounting\Support\AccountingAuthorization;
use App\Modules\Accounting\Support\AccountingTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ManageAccountingPeriodAction
{
    public function __construct(private readonly AccountingTransaction $tx, private readonly AccountingAuthorization $auth, private readonly AccountingAuditWriter $audit) {}

    public function create(string $tenantId, User $actor, string $startDate, string $endDate): string
    {
        $this->auth->authorize($tenantId, $actor, 'close_period');

        return $this->tx->run(function () use ($tenantId, $actor, $startDate, $endDate): string {
            $id = (string) Str::ulid();
            $at = now();
            DB::table('accounting_periods')->insert(['id' => $id, 'tenant_id' => $tenantId, 'start_date' => $startDate, 'end_date' => $endDate, 'status' => 'open', 'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => $at, 'updated_at' => $at]);
            $this->audit->write($tenantId, 'period.created', 'accounting_period', $id, (int) $actor->id, [], $at);

            return $id;
        });
    }

    public function changeBoundaries(string $tenantId, string $periodId, User $actor, string $startDate, string $endDate): void
    {
        $this->auth->authorize($tenantId, $actor, 'close_period');
        $this->tx->run(function () use ($tenantId, $periodId, $actor, $startDate, $endDate): void {
            DB::table('accounting_periods')->where('tenant_id', $tenantId)->where('id', $periodId)->lockForUpdate()->first() ?? throw new AccountingValidationFailed('Period was not found.');
            $at = now();
            DB::table('accounting_periods')->where('tenant_id', $tenantId)->where('id', $periodId)->update(['start_date' => $startDate, 'end_date' => $endDate, 'updated_by' => $actor->id, 'updated_at' => $at]);
            $this->audit->write($tenantId, 'period.boundaries_changed', 'accounting_period', $periodId, (int) $actor->id, [], $at);
        });
    }

    public function close(string $tenantId, string $periodId, User $actor): void
    {
        $this->transition($tenantId, $periodId, $actor, true, null);
    }

    public function reopen(string $tenantId, string $periodId, User $actor, string $reason): void
    {
        $this->transition($tenantId, $periodId, $actor, false, $reason);
    }

    private function transition(string $tenantId, string $periodId, User $actor, bool $close, ?string $reason): void
    {
        $this->auth->authorize($tenantId, $actor, $close ? 'close_period' : 'reopen_period');
        if (! $close && trim((string) $reason) === '') {
            throw new AccountingValidationFailed('Reopen reason is required.');
        }
        $this->tx->run(function () use ($tenantId, $periodId, $actor, $close, $reason): void {
            $at = now();
            $period = DB::table('accounting_periods')->where('tenant_id', $tenantId)->where('id', $periodId)->lockForUpdate()->first() ?? throw new AccountingValidationFailed('Period was not found.');
            if ($period->status !== ($close ? 'open' : 'closed')) {
                throw new AccountingValidationFailed('Invalid period transition.');
            }$values = $close ? ['status' => 'closed', 'closed_at' => $at, 'closed_by' => $actor->id, 'reopened_at' => null, 'reopened_by' => null, 'reopen_reason' => null] : ['status' => 'open', 'closed_at' => null, 'closed_by' => null, 'reopened_at' => $at, 'reopened_by' => $actor->id, 'reopen_reason' => $reason];
            DB::table('accounting_periods')->where('tenant_id', $tenantId)->where('id', $periodId)->update(array_merge($values, ['updated_by' => $actor->id, 'updated_at' => $at]));
            $this->audit->write($tenantId, $close ? 'period.closed' : 'period.reopened', 'accounting_period', $periodId, (int) $actor->id, $close ? [] : ['reason' => $reason], $at);
        });
    }
}
