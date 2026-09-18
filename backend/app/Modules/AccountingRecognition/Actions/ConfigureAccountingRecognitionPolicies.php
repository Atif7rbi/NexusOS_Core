<?php

declare(strict_types=1);

namespace App\Modules\AccountingRecognition\Actions;

use App\Models\User;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionConflict;
use App\Modules\AccountingRecognition\Exceptions\AccountingRecognitionValidationFailed;
use App\Modules\AccountingRecognition\Support\AccountingRecognitionAuthorization;
use App\Modules\AccountingRecognition\Support\AccountingRecognitionTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConfigureAccountingRecognitionPolicies
{
    public function __construct(
        private readonly AccountingRecognitionTransaction $transaction,
        private readonly AccountingRecognitionAuthorization $authorization,
    ) {}

    public function receivableAr(
        string $tenantId,
        User $actor,
        string $effectiveFrom,
        string $arControlAccountId,
    ): array {
        return $this->replace(
            $tenantId,
            $actor,
            'receivable_ar_policies',
            $effectiveFrom,
            ['ar_control_account_id' => $arControlAccountId],
            ['ar_control_account_id' => 'asset'],
        );
    }

    public function counterpart(
        string $tenantId,
        User $actor,
        string $effectiveFrom,
        string $contractAssetControlAccountId,
        string $contractLiabilityControlAccountId,
    ): array {
        return $this->replace(
            $tenantId,
            $actor,
            'contract_consideration_accounting_policies',
            $effectiveFrom,
            [
                'contract_asset_control_account_id' => $contractAssetControlAccountId,
                'contract_liability_control_account_id' => $contractLiabilityControlAccountId,
            ],
            [
                'contract_asset_control_account_id' => 'asset',
                'contract_liability_control_account_id' => 'liability',
            ],
        );
    }

    public function performance(
        string $tenantId,
        User $actor,
        string $effectiveFrom,
        string $revenueAccountId,
        string $contractAssetControlAccountId,
        string $contractLiabilityControlAccountId,
    ): array {
        return $this->replace(
            $tenantId,
            $actor,
            'performance_accounting_policies',
            $effectiveFrom,
            [
                'revenue_account_id' => $revenueAccountId,
                'contract_asset_control_account_id' => $contractAssetControlAccountId,
                'contract_liability_control_account_id' => $contractLiabilityControlAccountId,
            ],
            [
                'revenue_account_id' => 'revenue',
                'contract_asset_control_account_id' => 'asset',
                'contract_liability_control_account_id' => 'liability',
            ],
        );
    }

    private function replace(
        string $tenantId,
        User $actor,
        string $table,
        string $effectiveFrom,
        array $accountMappings,
        array $requiredAccountTypes,
    ): array {
        $this->authorization->authorize($tenantId, $actor);
        $date = $this->date($effectiveFrom);

        foreach ($accountMappings as $accountId) {
            if (! is_string($accountId) || ! Str::isUlid($accountId)) {
                throw new AccountingRecognitionValidationFailed(
                    'Accounting Recognition policy account IDs must be ULIDs.',
                );
            }
        }

        return $this->transaction->run(function () use (
            $tenantId,
            $actor,
            $table,
            $date,
            $accountMappings,
            $requiredAccountTypes,
        ): array {
            $this->authorization->authorizeTransactional($tenantId, $actor);

            DB::table('accounting_settings')
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first()
                ?? throw new AccountingRecognitionValidationFailed(
                    'Accounting must be active before configuring recognition policies.',
                );

            $history = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->orderBy('policy_version')
                ->lockForUpdate()
                ->get();

            $active = $history->firstWhere('status', 'active');
            if ($active !== null && $history->where('status', 'active')->count() !== 1) {
                throw new AccountingRecognitionConflict(
                    'Recognition policy history has more than one active version.',
                );
            }

            if ($active !== null && $date <= (string) $active->effective_from) {
                throw new AccountingRecognitionValidationFailed(
                    'A successor policy must begin after the current active policy.',
                );
            }

            $accountIds = array_values(array_unique(array_values($accountMappings)));
            sort($accountIds, SORT_STRING);
            $accounts = DB::table('accounts')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $accountIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($requiredAccountTypes as $field => $accountType) {
                $accountId = $accountMappings[$field];
                $account = $accounts->get($accountId);
                if (
                    $account === null
                    || $account->kind !== 'posting'
                    || $account->status !== 'active'
                    || $account->account_type !== $accountType
                ) {
                    throw new AccountingRecognitionValidationFailed(
                        'Recognition policy account is not eligible for its frozen semantic role.',
                    );
                }
            }

            $now = CarbonImmutable::now('UTC');
            if ($active !== null) {
                $closedThrough = CarbonImmutable::parse($date, 'UTC')
                    ->subDay()
                    ->toDateString();

                $updated = DB::table($table)
                    ->where('tenant_id', $tenantId)
                    ->where('id', $active->id)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'superseded',
                        'effective_to' => $closedThrough,
                        'superseded_by' => $actor->id,
                        'superseded_at' => $now,
                    ]);

                if ($updated !== 1) {
                    throw new AccountingRecognitionConflict(
                        'Recognition policy changed concurrently.',
                    );
                }
            }

            $id = (string) Str::ulid();
            $version = ((int) ($history->max('policy_version') ?? 0)) + 1;

            DB::table($table)->insert(array_merge(
                [
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'policy_version' => $version,
                    'status' => 'active',
                    'effective_from' => $date,
                    'effective_to' => null,
                    'created_by' => $actor->id,
                    'created_at' => $now,
                    'superseded_by' => null,
                    'superseded_at' => null,
                ],
                $accountMappings,
            ));

            return [
                'status' => 'committed',
                'policy_id' => $id,
                'policy_version' => $version,
                'effective_from' => $date,
            ];
        });
    }

    private function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new AccountingRecognitionValidationFailed(
                'Policy effective_from must be a valid YYYY-MM-DD date.',
            );
        }

        return $value;
    }
}
