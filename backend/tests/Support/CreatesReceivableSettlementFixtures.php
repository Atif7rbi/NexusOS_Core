<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\AccountingRecognition\Actions\ConfigureAccountingRecognitionPolicies;
use App\Modules\AccountingRecognition\Actions\RecognizeReceivableAr;
use App\Modules\ContractualBilling\Actions\EstablishEntitlementReceivable;
use App\Modules\Payments\Actions\AllocatePaymentAction;
use App\Modules\Payments\Actions\RecordPaymentAction;
use App\Modules\ReceiptEvidence\Actions\ApproveReceivingAccount;
use App\Modules\ReceiptEvidence\Actions\AssociateReceiptWithPayment;
use App\Modules\ReceiptEvidence\Actions\ConfigureBankReceiptCashClearingPolicy;
use App\Modules\ReceiptEvidence\Actions\ConfigureReceivingAccountCashMapping;
use App\Modules\ReceiptEvidence\Actions\PostVerifiedBankReceiptCash;
use App\Modules\ReceiptEvidence\Actions\VerifyBankReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait CreatesReceivableSettlementFixtures
{
    use CreatesContractConsiderationFixtures;

    protected function settlementContext(string $allocationAmount = '1000.00'): array
    {
        $context = $this->considerationContext();
        $obligationId = $this->billingObligations(
            $context,
            ['1000.00'],
            '2026-08-21',
        )[0];
        $consideration = $this->adopt($context);

        app(ActivateAccountingAction::class)->execute(
            $context['tenant_id'],
            $context['actor'],
        );
        app(ManageAccountingPeriodAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            '2026-12-31',
        );

        $accounts = [
            'ar' => $this->settlementAccount($context, 'SET-AR', 'asset', 'current_asset'),
            'contract_asset' => $this->settlementAccount($context, 'SET-CA', 'asset', 'current_asset'),
            'contract_liability' => $this->settlementAccount($context, 'SET-CL', 'liability', 'current_liability'),
            'cash' => $this->settlementAccount($context, 'SET-CASH', 'asset', 'current_asset'),
            'clearing' => $this->settlementAccount($context, 'SET-CLEAR', 'liability', 'current_liability'),
        ];
        app(ConfigureAccountingRecognitionPolicies::class)->receivableAr(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $accounts['ar'],
        );
        app(ConfigureAccountingRecognitionPolicies::class)->counterpart(
            $context['tenant_id'],
            $context['actor'],
            '2026-01-01',
            $accounts['contract_asset'],
            $accounts['contract_liability'],
        );

        [$source] = DB::transaction(function () use (
            $context,
            $consideration,
            $obligationId,
        ): array {
            $source = $this->billingSource($context, $obligationId);
            $graph = $this->transition(
                $context,
                $consideration,
                $source,
                $consideration['genesis_lot_id'],
                'BILLED_UNEARNED',
            );

            return [$source, $graph];
        });
        $receivableId = app(EstablishEntitlementReceivable::class)->execute(
            $context['tenant_id'],
            $source['id'],
            $context['actor'],
            ['receivable_establishment_operation_id' => (string) Str::ulid()],
        );
        $recognitionId = app(RecognizeReceivableAr::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'contractual_billing_entitlement_id' => $source['id'],
                'receivable_ar_operation_id' => (string) Str::ulid(),
            ],
        );

        $paymentId = app(RecordPaymentAction::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'payment_operation_id' => (string) Str::ulid(),
                'customer_id' => $context['customer_id'],
                'amount' => '1000.00',
                'currency' => 'SAR',
                'received_on' => '2026-08-22',
            ],
        );
        $allocationId = app(AllocatePaymentAction::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'payment_id' => $paymentId,
                'receivable_id' => $receivableId,
                'allocation_operation_id' => (string) Str::ulid(),
                'amount' => $allocationAmount,
            ],
        );

        $receivingAccountId = app(ApproveReceivingAccount::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'receiving_account_operation_id' => (string) Str::ulid(),
                'institution_identifier' => 'settlement-bank',
                'account_identity' => 'iban-'.Str::lower((string) Str::ulid()),
                'masked_account_identity' => 'SA**9000',
                'valid_from' => '2026-01-01',
            ],
        );
        $receiptId = app(VerifyBankReceipt::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'receipt_operation_id' => (string) Str::ulid(),
                'receiving_account_id' => $receivingAccountId,
                'source_identity_kind' => 'bank_transaction_id',
                'source_identity_version' => 1,
                'source_identity' => 'settlement-'.Str::lower((string) Str::ulid()),
                'amount' => '1000.00',
                'currency' => 'SAR',
                'control_date' => '2026-08-22',
                'evidence_reference' => 'statement/settlement',
            ],
        );
        $associationId = app(AssociateReceiptWithPayment::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'association_operation_id' => (string) Str::ulid(),
                'receipt_id' => $receiptId,
                'payment_id' => $paymentId,
            ],
        );
        app(ConfigureReceivingAccountCashMapping::class)->execute(
            $context['tenant_id'],
            $receivingAccountId,
            $context['actor'],
            [
                'mapping_operation_id' => (string) Str::ulid(),
                'cash_account_id' => $accounts['cash'],
            ],
        );
        $cashPolicyId = app(ConfigureBankReceiptCashClearingPolicy::class)->execute(
            $context['tenant_id'],
            $context['actor'],
            [
                'policy_operation_id' => (string) Str::ulid(),
                'clearing_account_id' => $accounts['clearing'],
            ],
        );
        $cashPosting = app(PostVerifiedBankReceiptCash::class)->execute(
            $context['tenant_id'],
            $receiptId,
            $context['actor'],
            ['posting_operation_id' => (string) Str::ulid()],
        );

        return compact(
            'context',
            'accounts',
            'source',
            'receivableId',
            'recognitionId',
            'paymentId',
            'allocationId',
            'receivingAccountId',
            'receiptId',
            'associationId',
            'cashPolicyId',
            'cashPosting',
        );
    }

    protected function settlementAccount(
        array $context,
        string $code,
        string $type,
        string $classification,
    ): string {
        return app(ManageAccountAction::class)->create(
            $context['tenant_id'],
            $context['actor'],
            [
                'code' => $code,
                'name' => 'Settlement '.$code,
                'description' => null,
                'kind' => 'posting',
                'account_type' => $type,
                'classification' => $classification,
                'parent_id' => null,
            ],
        );
    }
}
