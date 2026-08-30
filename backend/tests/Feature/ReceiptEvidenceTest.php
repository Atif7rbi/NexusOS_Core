<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Payments\Actions\CancelPaymentAction;
use App\Modules\Payments\Actions\RecordPaymentAction;
use App\Modules\Payments\Exceptions\PaymentsConflict;
use App\Modules\ReceiptEvidence\Actions\ApproveReceivingAccount;
use App\Modules\ReceiptEvidence\Actions\AssociateReceiptWithPayment;
use App\Modules\ReceiptEvidence\Actions\CancelReceiptPaymentAssociation;
use App\Modules\ReceiptEvidence\Actions\InvalidateBankReceipt;
use App\Modules\ReceiptEvidence\Actions\RetireReceivingAccount;
use App\Modules\ReceiptEvidence\Actions\VerifyBankReceipt;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ReceiptEvidenceTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_receipt_is_independent_from_payment_and_exact_association_blocks_payment_cancellation(): void
    {
        [$tenantId, $actor, $customerId] = $this->context();
        $accountId = $this->account($tenantId, $actor);
        $paymentId = $this->payment($tenantId, $actor, $customerId, '25.00');
        $receiptId = $this->receipt($tenantId, $actor, $accountId, '25.00');
        $associationId = app(AssociateReceiptWithPayment::class)->execute($tenantId, $actor, ['association_operation_id' => (string) Str::ulid(), 'receipt_id' => $receiptId, 'payment_id' => $paymentId]);

        try {
            app(CancelPaymentAction::class)->execute($tenantId, $paymentId, $actor, 'Correction');
            self::fail('Payment cancellation accepted an effective receipt association.');
        } catch (PaymentsConflict) {
            self::assertTrue(true);
        }

        app(CancelReceiptPaymentAssociation::class)->execute($tenantId, $associationId, $actor, ['cancellation_operation_id' => (string) Str::ulid(), 'cancellation_reason' => 'Wrong attribution']);
        app(CancelPaymentAction::class)->execute($tenantId, $paymentId, $actor, 'Correction');
        self::assertSame('effective', DB::table('bank_receipt_evidence')->where('id', $receiptId)->value('status'));
    }

    public function test_source_and_operation_replay_are_safe_and_different_facts_conflict(): void
    {
        [$tenantId, $actor] = $this->context();
        $accountId = $this->account($tenantId, $actor);
        $operation = (string) Str::ulid();
        $input = $this->receiptInput($accountId, '10.00', $operation, 'transaction-1');
        $receiptId = app(VerifyBankReceipt::class)->execute($tenantId, $actor, $input);

        self::assertSame($receiptId, app(VerifyBankReceipt::class)->execute($tenantId, $actor, $input));
        $input['amount'] = '11.00';
        $this->expectException(ReceiptEvidenceConflict::class);
        app(VerifyBankReceipt::class)->execute($tenantId, $actor, $input);
    }

    public function test_terminal_operation_replays_without_rewriting_and_conflicts_when_reused(): void
    {
        [$tenantId, $actor] = $this->context();
        $accountId = $this->account($tenantId, $actor);
        $operation = (string) Str::ulid();
        $facts = ['retirement_operation_id' => $operation, 'retired_from' => now()->addDay()->toDateString(), 'retirement_reason' => 'Account closed'];
        $action = app(RetireReceivingAccount::class);
        $action->execute($tenantId, $accountId, $actor, $facts);
        $first = DB::table('approved_receiving_accounts')->where('id', $accountId)->firstOrFail();
        $action->execute($tenantId, $accountId, $actor, $facts);
        self::assertSame($first->retired_at, DB::table('approved_receiving_accounts')->where('id', $accountId)->value('retired_at'));

        $otherAccount = $this->account($tenantId, $actor);
        $this->expectException(ReceiptEvidenceConflict::class);
        $action->execute($tenantId, $otherAccount, $actor, $facts);
    }

    public function test_retirement_preserves_historical_eligibility_and_direct_sql_cannot_mutate_receipt_truth(): void
    {
        [$tenantId, $actor] = $this->context();
        $accountId = $this->account($tenantId, $actor, now()->subDays(2)->toDateString());
        $receiptId = $this->receipt($tenantId, $actor, $accountId, '12.00', now()->subDay()->toDateString());
        app(RetireReceivingAccount::class)->execute($tenantId, $accountId, $actor, ['retirement_operation_id' => (string) Str::ulid(), 'retired_from' => now()->toDateString(), 'retirement_reason' => 'Account closed']);
        self::assertSame('effective', DB::table('bank_receipt_evidence')->where('id', $receiptId)->value('status'));

        $this->expectException(QueryException::class);
        DB::table('bank_receipt_evidence')->where('id', $receiptId)->update(['amount' => '13.00']);
    }

    private function account(string $tenantId, object $actor, ?string $validFrom = null): string
    {
        return app(ApproveReceivingAccount::class)->execute($tenantId, $actor, ['receiving_account_operation_id' => (string) Str::ulid(), 'institution_identifier' => 'bank-1', 'account_identity' => 'iban-'.Str::lower((string) Str::ulid()), 'masked_account_identity' => 'SA**1234', 'valid_from' => $validFrom ?? now()->subDay()->toDateString()]);
    }

    private function receipt(string $tenantId, object $actor, string $accountId, string $amount, ?string $controlDate = null): string
    {
        return app(VerifyBankReceipt::class)->execute($tenantId, $actor, $this->receiptInput($accountId, $amount, (string) Str::ulid(), 'transaction-'.Str::lower((string) Str::ulid()), $controlDate));
    }

    private function receiptInput(string $accountId, string $amount, string $operation, string $source, ?string $controlDate = null): array
    {
        return ['receipt_operation_id' => $operation, 'receiving_account_id' => $accountId, 'source_identity_kind' => 'bank_transaction_id', 'source_identity_version' => 1, 'source_identity' => $source, 'amount' => $amount, 'currency' => 'SAR', 'control_date' => $controlDate ?? now()->toDateString(), 'evidence_reference' => 'statement/2026/line/1'];
    }

    private function payment(string $tenantId, object $actor, string $customerId, string $amount): string
    {
        return app(RecordPaymentAction::class)->execute($tenantId, $actor, ['payment_operation_id' => (string) Str::ulid(), 'customer_id' => $customerId, 'amount' => $amount, 'currency' => 'SAR', 'received_on' => now()->toDateString()]);
    }

    private function context(): array
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = (string) TenantUser::query()->where('user_id', $actor->id)->value('tenant_id');
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);

        return [$tenantId, $actor, (string) $customer->id];
    }
}
