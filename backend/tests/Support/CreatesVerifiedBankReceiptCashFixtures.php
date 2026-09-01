<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\ReceiptEvidence\Actions\ApproveReceivingAccount;
use App\Modules\ReceiptEvidence\Actions\VerifyBankReceipt;
use Illuminate\Support\Str;

trait CreatesVerifiedBankReceiptCashFixtures
{
    /** @return array{Tenant,User,string,string,string,string} */
    private function cashPostingContext(): array
    {
        $tenant = Tenant::factory()->create(['currency' => 'SAR', 'status' => Tenant::STATUS_ACTIVE]);
        $actor = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'status' => User::STATUS_ACTIVE]);
        TenantUser::factory()->forTenant($tenant)->forUser($actor)->active()->create();
        app(ActivateAccountingAction::class)->execute((string) $tenant->id, $actor);
        app(ManageAccountingPeriodAction::class)->create((string) $tenant->id, $actor, '2026-01-01', '2026-12-31');
        $cash = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, $this->cashAccount('1100', 'asset', 'current_asset'));
        $clearing = app(ManageAccountAction::class)->create((string) $tenant->id, $actor, $this->cashAccount('2100', 'liability', 'current_liability'));
        $receiving = app(ApproveReceivingAccount::class)->execute((string) $tenant->id, $actor, ['receiving_account_operation_id' => (string) Str::ulid(), 'institution_identifier' => 'bank-1', 'account_identity' => 'iban-'.Str::lower((string) Str::ulid()), 'masked_account_identity' => 'SA**1234', 'valid_from' => '2026-01-01']);
        $receipt = app(VerifyBankReceipt::class)->execute((string) $tenant->id, $actor, ['receipt_operation_id' => (string) Str::ulid(), 'receiving_account_id' => $receiving, 'source_identity_kind' => 'bank_transaction_id', 'source_identity_version' => 1, 'source_identity' => 'bank-'.Str::lower((string) Str::ulid()), 'amount' => '25.00', 'currency' => 'SAR', 'control_date' => '2026-08-01', 'evidence_reference' => 'statement/line']);

        return [$tenant, $actor, $cash, $clearing, $receiving, $receipt];
    }

    private function cashAccount(string $code, string $type, string $classification): array
    {
        return ['code' => $code, 'name' => 'Cash '.$code, 'description' => null, 'kind' => 'posting', 'account_type' => $type, 'classification' => $classification, 'parent_id' => null];
    }
}
