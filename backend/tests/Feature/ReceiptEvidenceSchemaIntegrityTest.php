<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Payments\Actions\CancelPaymentAction;
use App\Modules\Payments\Actions\RecordPaymentAction;
use App\Modules\ReceiptEvidence\Actions\ApproveReceivingAccount;
use App\Modules\ReceiptEvidence\Actions\AssociateReceiptWithPayment;
use App\Modules\ReceiptEvidence\Actions\CancelReceiptPaymentAssociation;
use App\Modules\ReceiptEvidence\Actions\InvalidateBankReceipt;
use App\Modules\ReceiptEvidence\Actions\VerifyBankReceipt;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ReceiptEvidenceSchemaIntegrityTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_direct_sql_rejects_cross_tenant_receiving_account_and_payment_references(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();
        [, $otherActor, $otherCustomerId] = $this->context();
        $otherTenantId = (string) TenantUser::query()->where('user_id', $otherActor->id)->value('tenant_id');
        $otherAccountId = $this->account($otherTenantId, $otherActor);
        $receiptId = $this->receipt($tenantId, $actor, $this->account($tenantId, $actor), '10.00');
        $otherPaymentId = $this->payment($otherTenantId, $otherActor, $otherCustomerId, '10.00');

        $this->assertRejected(fn () => $this->insertReceipt($tenantId, $actor, $otherAccountId, 'cross-tenant-account'));
        $this->assertRejected(fn () => $this->insertAssociation($tenantId, $actor, $receiptId, $otherPaymentId, '10.00', 'SAR'));
    }

    public function test_direct_sql_rejects_immutable_receipt_canonical_fact_mutation_and_duplicate_source_identity(): void
    {
        [$tenantId, $actor] = $this->context();
        $accountId = $this->account($tenantId, $actor);
        $receiptId = $this->receipt($tenantId, $actor, $accountId, '10.00', 'source-immutable');

        $this->assertRejected(fn () => DB::table('bank_receipt_evidence')->where('id', $receiptId)->update(['amount' => '11.00']));
        $this->assertRejected(fn () => DB::table('bank_receipt_evidence')->where('id', $receiptId)->update(['source_identity' => 'source-rewritten']));
        $this->assertRejected(fn () => $this->insertReceipt($tenantId, $actor, $accountId, 'source-immutable'));
    }

    public function test_direct_sql_rejects_ineligible_or_inexact_receipt_payment_associations(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();

        $cancelledPaymentId = $this->payment($tenantId, $actor, $customerId, '10.00');
        app(CancelPaymentAction::class)->execute($tenantId, $cancelledPaymentId, $actor, 'Receipt not received');
        $this->assertRejected(fn () => $this->insertAssociation($tenantId, $actor, $this->receipt($tenantId, $actor, $this->account($tenantId, $actor), '10.00'), $cancelledPaymentId, '10.00', 'SAR'));

        $invalidatedReceiptId = $this->receipt($tenantId, $actor, $this->account($tenantId, $actor), '10.00');
        app(InvalidateBankReceipt::class)->execute($tenantId, $invalidatedReceiptId, $actor, [
            'invalidation_operation_id' => (string) Str::ulid(),
            'invalidation_reason' => 'Source correction',
        ]);
        $this->assertRejected(fn () => $this->insertAssociation($tenantId, $actor, $invalidatedReceiptId, $this->payment($tenantId, $actor, $customerId, '10.00'), '10.00', 'SAR'));

        $receiptId = $this->receipt($tenantId, $actor, $this->account($tenantId, $actor), '10.00');
        $paymentId = $this->payment($tenantId, $actor, $customerId, '10.00');
        $this->assertRejected(fn () => $this->insertAssociation($tenantId, $actor, $receiptId, $paymentId, '9.99', 'SAR'));
        $this->assertRejected(fn () => $this->insertAssociation($tenantId, $actor, $receiptId, $paymentId, '10.00', 'USD'));
    }

    public function test_direct_sql_cannot_rewrite_terminal_receipt_or_association_history(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();
        $receiptId = $this->receipt($tenantId, $actor, $this->account($tenantId, $actor), '10.00');
        app(InvalidateBankReceipt::class)->execute($tenantId, $receiptId, $actor, [
            'invalidation_operation_id' => (string) Str::ulid(),
            'invalidation_reason' => 'Source correction',
        ]);

        $this->assertRejected(fn () => DB::table('bank_receipt_evidence')->where('id', $receiptId)->update(['invalidation_reason' => 'Rewritten reason']));
        $this->assertRejected(fn () => DB::table('bank_receipt_evidence')->where('id', $receiptId)->update([
            'status' => 'effective',
            'invalidation_operation_id' => null,
            'invalidated_by' => null,
            'invalidated_at' => null,
            'invalidation_reason' => null,
        ]));

        $associationReceiptId = $this->receipt($tenantId, $actor, $this->account($tenantId, $actor), '10.00');
        $associationId = app(AssociateReceiptWithPayment::class)->execute($tenantId, $actor, [
            'association_operation_id' => (string) Str::ulid(),
            'receipt_id' => $associationReceiptId,
            'payment_id' => $this->payment($tenantId, $actor, $customerId, '10.00'),
        ]);
        app(CancelReceiptPaymentAssociation::class)->execute($tenantId, $associationId, $actor, [
            'cancellation_operation_id' => (string) Str::ulid(),
            'cancellation_reason' => 'Wrong attribution',
        ]);

        $this->assertRejected(fn () => DB::table('receipt_payment_associations')->where('id', $associationId)->update(['cancellation_reason' => 'Rewritten reason']));
        $this->assertRejected(fn () => DB::table('receipt_payment_associations')->where('id', $associationId)->update([
            'status' => 'effective',
            'cancellation_operation_id' => null,
            'cancelled_by' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ]));
    }

    public function test_runtime_role_cannot_directly_execute_receipt_evidence_integrity_functions(): void
    {
        $role = (string) getenv('ACCOUNTING_RUNTIME_DB_ROLE');
        $functions = [
            'enforce_receiving_account_history',
            'enforce_bank_receipt_evidence_history',
            'enforce_receipt_payment_association_history',
            'enforce_payment_receipt_association_guard',
        ];

        foreach ($functions as $function) {
            self::assertFalse((bool) DB::selectOne("SELECT has_function_privilege(?, 'public.' || ? || '()', 'EXECUTE') allowed", [$role, $function])->allowed);
        }

        DB::statement('SET ROLE "'.str_replace('"', '""', $role).'"');
        try {
            foreach ($functions as $function) {
                $this->assertRejected(fn () => DB::unprepared("SELECT public.{$function}()"));
            }
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    private function context(): array
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = (string) TenantUser::query()->where('user_id', $actor->id)->value('tenant_id');
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);

        return [$tenantId, $actor, (string) $customer->id];
    }

    private function account(string $tenantId, User $actor): string
    {
        return app(ApproveReceivingAccount::class)->execute($tenantId, $actor, [
            'receiving_account_operation_id' => (string) Str::ulid(),
            'institution_identifier' => 'bank-'.Str::lower((string) Str::ulid()),
            'account_identity' => 'iban-'.Str::lower((string) Str::ulid()),
            'masked_account_identity' => 'SA**1234',
            'valid_from' => now()->subDay()->toDateString(),
        ]);
    }

    private function receipt(string $tenantId, User $actor, string $accountId, string $amount, ?string $source = null): string
    {
        return app(VerifyBankReceipt::class)->execute($tenantId, $actor, [
            'receipt_operation_id' => (string) Str::ulid(),
            'receiving_account_id' => $accountId,
            'source_identity_kind' => 'bank_transaction_id',
            'source_identity_version' => 1,
            'source_identity' => $source ?? 'transaction-'.Str::lower((string) Str::ulid()),
            'amount' => $amount,
            'currency' => 'SAR',
            'control_date' => now()->toDateString(),
            'evidence_reference' => 'statement/2026/line/1',
        ]);
    }

    private function payment(string $tenantId, User $actor, string $customerId, string $amount): string
    {
        return app(RecordPaymentAction::class)->execute($tenantId, $actor, [
            'payment_operation_id' => (string) Str::ulid(),
            'customer_id' => $customerId,
            'amount' => $amount,
            'currency' => 'SAR',
            'received_on' => now()->toDateString(),
        ]);
    }

    private function insertReceipt(string $tenantId, User $actor, string $accountId, string $source): void
    {
        $now = now();
        DB::table('bank_receipt_evidence')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'receipt_operation_id' => (string) Str::ulid(),
            'receiving_account_id' => $accountId,
            'channel' => 'bank_transfer',
            'source_identity_kind' => 'bank_transaction_id',
            'source_identity_version' => 1,
            'source_identity' => $source,
            'amount' => '10.00',
            'currency' => 'SAR',
            'control_date' => now()->toDateString(),
            'evidence_reference' => 'statement/2026/line/1',
            'verification_method' => 'manual_receiving_side_bank_evidence',
            'verified_by' => $actor->id,
            'verified_at' => $now,
            'status' => 'effective',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertAssociation(string $tenantId, User $actor, string $receiptId, string $paymentId, string $amount, string $currency): void
    {
        $now = now();
        DB::table('receipt_payment_associations')->insert([
            'id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'association_operation_id' => (string) Str::ulid(),
            'receipt_id' => $receiptId,
            'payment_id' => $paymentId,
            'associated_amount' => $amount,
            'currency' => $currency,
            'associated_by' => $actor->id,
            'associated_at' => $now,
            'status' => 'effective',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assertRejected(callable $mutation): void
    {
        DB::beginTransaction();
        try {
            $mutation();
            self::fail('Invalid direct-SQL Receipt Evidence mutation was accepted.');
        } catch (QueryException) {
            self::assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }
}
