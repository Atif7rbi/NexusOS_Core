<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Payments\Actions\CancelPaymentAction;
use App\Modules\Payments\Actions\RecordPaymentAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Support\CreatesActiveMembership;
use Tests\Support\CreatesDomainIntegrityFixtures;
use Tests\TestCase;

final class ReceiptEvidenceApiTest extends TestCase
{
    use CreatesActiveMembership;
    use CreatesDomainIntegrityFixtures;
    use RefreshDatabase;

    public function test_authentication_tenant_isolation_and_cross_tenant_target_mutations_are_enforced(): void
    {
        $this->getJson('/api/receipt-evidence/receipts')->assertUnauthorized();

        [$first, $firstTenant, $customerId] = $this->context();
        $firstToken = $this->createAccessToken($first);
        $accountId = $this->approve($firstToken);
        $receiptId = $this->verify($firstToken, $accountId);
        $paymentId = $this->payment($firstTenant, $first, $customerId, '10.00');
        $associationId = $this->associate($firstToken, $receiptId, $paymentId);

        [$second] = $this->context();
        $secondToken = $this->createAccessToken($second);

        Auth::forgetGuards();

        $this->withToken($secondToken)->getJson("/api/receipt-evidence/receiving-accounts/{$accountId}")->assertNotFound();
        $this->withToken($secondToken)->getJson("/api/receipt-evidence/receipts/{$receiptId}")->assertNotFound();
        $this->withToken($secondToken)->getJson("/api/receipt-evidence/associations/{$associationId}")->assertNotFound();
        $this->withToken($secondToken)->postJson("/api/receipt-evidence/receiving-accounts/{$accountId}/retire", $this->retirementPayload())->assertNotFound();
        $this->withToken($secondToken)->postJson("/api/receipt-evidence/receipts/{$receiptId}/invalidate", $this->invalidationPayload())->assertNotFound();
        $this->withToken($secondToken)->postJson("/api/receipt-evidence/associations/{$associationId}/cancel", $this->cancellationPayload())->assertNotFound();

        Auth::forgetGuards();

        $this->withToken($firstToken)->postJson('/api/receipt-evidence/receiving-accounts', [
            ...$this->accountPayload(),
            'tenant_id' => (string) Str::ulid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
    }

    public function test_receiving_accounts_are_readable_without_full_identity_and_support_replay_retirement_validation_and_not_found(): void
    {
        [$actor] = $this->context();
        $token = $this->createAccessToken($actor);
        $payload = $this->accountPayload();

        $account = $this->withToken($token)
            ->postJson('/api/receipt-evidence/receiving-accounts', $payload)
            ->assertOk()
            ->json('data.receiving_account');

        self::assertArrayNotHasKey('account_identity', $account);
        self::assertArrayNotHasKey('tenant_id', $account);
        $this->withToken($token)->getJson('/api/receipt-evidence/receiving-accounts')->assertOk()->assertJsonPath('data.receiving_accounts.data.0.id', $account['id'])->assertJsonMissingPath('data.receiving_accounts.data.0.account_identity');
        $this->withToken($token)->getJson("/api/receipt-evidence/receiving-accounts/{$account['id']}")->assertOk()->assertJsonMissingPath('data.receiving_account.account_identity');

        $replayedId = $this->withToken($token)
            ->postJson('/api/receipt-evidence/receiving-accounts', $payload)
            ->assertOk()
            ->json('data.receiving_account.id');
        self::assertSame($account['id'], $replayedId);

        $this->withToken($token)->postJson('/api/receipt-evidence/receiving-accounts', [
            ...$payload,
            'institution_identifier' => 'different-bank',
        ])->assertConflict();
        $this->withToken($token)->postJson('/api/receipt-evidence/receiving-accounts', ['institution_identifier' => 'bank-1'])->assertUnprocessable();
        $this->withToken($token)->getJson('/api/receipt-evidence/receiving-accounts/'.Str::ulid())->assertNotFound();

        $retirement = $this->retirementPayload();
        $this->withToken($token)->postJson("/api/receipt-evidence/receiving-accounts/{$account['id']}/retire", $retirement)->assertOk()->assertJsonPath('data.receiving_account.status', 'retired');
        $this->withToken($token)->postJson("/api/receipt-evidence/receiving-accounts/{$account['id']}/retire", $retirement)->assertOk()->assertJsonPath('data.receiving_account.status', 'retired');
        $this->withToken($token)->postJson("/api/receipt-evidence/receiving-accounts/{$account['id']}/retire", $this->retirementPayload())->assertConflict();
    }

    public function test_receipts_use_server_constants_preserve_replay_and_conflict_and_enforce_replacement_lineage(): void
    {
        [$actor] = $this->context();
        $token = $this->createAccessToken($actor);
        $accountId = $this->approve($token);
        $payload = $this->receiptPayload($accountId);

        $receipt = $this->withToken($token)->postJson('/api/receipt-evidence/receipts', $payload)->assertOk()->json('data.receipt');
        self::assertSame('bank_transfer', $receipt['channel']);
        self::assertSame('manual_receiving_side_bank_evidence', $receipt['verification_method']);
        $this->assertReceiptProjection($receipt);

        $replayedId = $this->withToken($token)->postJson('/api/receipt-evidence/receipts', $payload)->assertOk()->json('data.receipt.id');
        self::assertSame($receipt['id'], $replayedId);
        $this->withToken($token)->postJson('/api/receipt-evidence/receipts', [...$payload, 'amount' => '11.00'])->assertConflict();
        $this->withToken($token)->postJson('/api/receipt-evidence/receipts', [...$this->receiptPayload($accountId), 'source_identity_kind' => 'unsupported'])->assertUnprocessable()->assertJsonValidationErrors('source_identity_kind');
        $this->withToken($token)->postJson('/api/receipt-evidence/receipts', [...$this->receiptPayload($accountId), 'channel' => 'client-owned'])->assertUnprocessable()->assertJsonValidationErrors('channel');

        $invalidation = $this->invalidationPayload();
        $this->withToken($token)->postJson("/api/receipt-evidence/receipts/{$receipt['id']}/invalidate", $invalidation)->assertOk()->assertJsonPath('data.receipt.status', 'invalidated');
        $this->withToken($token)->postJson("/api/receipt-evidence/receipts/{$receipt['id']}/invalidate", $invalidation)->assertOk()->assertJsonPath('data.receipt.status', 'invalidated');
        $this->withToken($token)->postJson("/api/receipt-evidence/receipts/{$receipt['id']}/invalidate", $this->invalidationPayload())->assertConflict();
        $replacementPayload = $this->receiptPayload($accountId, amount: '10.00');
        $this->withToken($token)->postJson("/api/receipt-evidence/receipts/{$receipt['id']}/replace", [...$replacementPayload, 'replaces_receipt_id' => (string) Str::ulid()])->assertUnprocessable()->assertJsonValidationErrors('replaces_receipt_id');
        $replacement = $this->withToken($token)->postJson("/api/receipt-evidence/receipts/{$receipt['id']}/replace", $replacementPayload)->assertOk()->json('data.receipt');
        self::assertSame($receipt['id'], $replacement['replaces_receipt_id']);

        $this->withToken($token)->getJson('/api/receipt-evidence/receipts')->assertOk()->assertJsonMissingPath('data.receipts.data.0.tenant_id')->assertJsonMissingPath('data.receipts.data.0.receipt_operation_id');
        $this->withToken($token)->getJson("/api/receipt-evidence/receipts/{$replacement['id']}")->assertOk()->assertJsonMissingPath('data.receipt.tenant_id')->assertJsonMissingPath('data.receipt.invalidation_operation_id');
    }

    public function test_effective_association_blocks_receipt_invalidation(): void
    {
        [$actor, $tenantId, $customerId] = $this->context();
        $token = $this->createAccessToken($actor);
        $accountId = $this->approve($token);
        $receiptId = $this->verify($token, $accountId);
        $paymentId = $this->payment($tenantId, $actor, $customerId, '10.00');
        $this->associate($token, $receiptId, $paymentId);

        $this->withToken($token)->postJson("/api/receipt-evidence/receipts/{$receiptId}/invalidate", $this->invalidationPayload())->assertConflict();
    }

    public function test_associations_require_exact_eligible_parents_and_support_replay_conflict_and_terminal_cancellation(): void
    {
        [$actor, $tenantId, $customerId] = $this->context();
        $token = $this->createAccessToken($actor);
        $accountId = $this->approve($token);
        $receiptId = $this->verify($token, $accountId);
        $paymentId = $this->payment($tenantId, $actor, $customerId, '10.00');
        $payload = $this->associationPayload($receiptId, $paymentId);

        $association = $this->withToken($token)->postJson('/api/receipt-evidence/associations', $payload)->assertOk()->json('data.association');
        self::assertSame('10.00', (string) $association['associated_amount']);
        self::assertSame('SAR', $association['currency']);
        $this->assertAssociationProjection($association);
        $replayedId = $this->withToken($token)->postJson('/api/receipt-evidence/associations', $payload)->assertOk()->json('data.association.id');
        self::assertSame($association['id'], $replayedId);

        $otherPaymentId = $this->payment($tenantId, $actor, $customerId, '10.00');
        $this->withToken($token)->postJson('/api/receipt-evidence/associations', [...$payload, 'payment_id' => $otherPaymentId])->assertConflict();
        $this->withToken($token)->postJson('/api/receipt-evidence/associations', [...$this->associationPayload($receiptId, $otherPaymentId), 'associated_amount' => '10.00'])->assertUnprocessable()->assertJsonValidationErrors('associated_amount');

        $wrongAmountReceipt = $this->verify($token, $accountId, amount: '11.00');
        $this->withToken($token)->postJson('/api/receipt-evidence/associations', $this->associationPayload($wrongAmountReceipt, $otherPaymentId))->assertConflict();
        $usdPaymentId = $this->payment($tenantId, $actor, $customerId, '10.00', 'USD');
        $currencyReceipt = $this->verify($token, $accountId, amount: '10.00');
        $this->withToken($token)->postJson('/api/receipt-evidence/associations', $this->associationPayload($currencyReceipt, $usdPaymentId))->assertConflict();

        $cancelledPaymentId = $this->payment($tenantId, $actor, $customerId, '10.00');
        app(CancelPaymentAction::class)->execute($tenantId, $cancelledPaymentId, $actor, 'Payment correction');
        $cancelledPaymentReceipt = $this->verify($token, $accountId, amount: '10.00');
        $this->withToken($token)->postJson('/api/receipt-evidence/associations', $this->associationPayload($cancelledPaymentReceipt, $cancelledPaymentId))->assertConflict();

        $invalidatedReceipt = $this->verify($token, $accountId, amount: '10.00');
        $this->withToken($token)->postJson("/api/receipt-evidence/receipts/{$invalidatedReceipt}/invalidate", $this->invalidationPayload())->assertOk();
        $eligiblePaymentId = $this->payment($tenantId, $actor, $customerId, '10.00');
        $this->withToken($token)->postJson('/api/receipt-evidence/associations', $this->associationPayload($invalidatedReceipt, $eligiblePaymentId))->assertConflict();

        $secondPaymentId = $this->payment($tenantId, $actor, $customerId, '10.00');
        $this->withToken($token)->postJson('/api/receipt-evidence/associations', $this->associationPayload($receiptId, $secondPaymentId))->assertConflict();

        $cancellation = $this->cancellationPayload();
        $this->withToken($token)->postJson("/api/receipt-evidence/associations/{$association['id']}/cancel", $cancellation)->assertOk()->assertJsonPath('data.association.status', 'cancelled');
        $this->withToken($token)->postJson("/api/receipt-evidence/associations/{$association['id']}/cancel", $cancellation)->assertOk()->assertJsonPath('data.association.status', 'cancelled');
        $this->withToken($token)->postJson("/api/receipt-evidence/associations/{$association['id']}/cancel", $this->cancellationPayload())->assertConflict();

        $this->withToken($token)->getJson('/api/receipt-evidence/associations')->assertOk()->assertJsonMissingPath('data.associations.data.0.tenant_id')->assertJsonMissingPath('data.associations.data.0.association_operation_id');
        $this->withToken($token)->getJson("/api/receipt-evidence/associations/{$association['id']}")->assertOk()->assertJsonMissingPath('data.association.cancellation_operation_id');
    }

    private function approve(string $token): string
    {
        return $this->withToken($token)
            ->postJson('/api/receipt-evidence/receiving-accounts', $this->accountPayload())
            ->assertOk()
            ->json('data.receiving_account.id');
    }

    private function verify(string $token, string $accountId, string $amount = '10.00'): string
    {
        return $this->withToken($token)
            ->postJson('/api/receipt-evidence/receipts', $this->receiptPayload($accountId, amount: $amount))
            ->assertOk()
            ->json('data.receipt.id');
    }

    private function associate(string $token, string $receiptId, string $paymentId): string
    {
        return $this->withToken($token)
            ->postJson('/api/receipt-evidence/associations', $this->associationPayload($receiptId, $paymentId))
            ->assertOk()
            ->json('data.association.id');
    }

    private function accountPayload(): array
    {
        return [
            'receiving_account_operation_id' => (string) Str::ulid(),
            'institution_identifier' => 'bank-'.Str::lower((string) Str::ulid()),
            'account_identity' => 'iban-'.Str::lower((string) Str::ulid()),
            'masked_account_identity' => 'SA**1234',
            'valid_from' => now()->subDay()->toDateString(),
        ];
    }

    private function receiptPayload(string $accountId, ?string $operation = null, string $amount = '10.00'): array
    {
        return [
            'receipt_operation_id' => $operation ?? (string) Str::ulid(),
            'receiving_account_id' => $accountId,
            'source_identity_kind' => 'bank_transaction_id',
            'source_identity_version' => 1,
            'source_identity' => 'transaction-'.Str::lower((string) Str::ulid()),
            'amount' => $amount,
            'currency' => 'SAR',
            'control_date' => now()->toDateString(),
            'evidence_reference' => 'statement/'.Str::lower((string) Str::ulid()),
        ];
    }

    private function associationPayload(string $receiptId, string $paymentId, ?string $operation = null): array
    {
        return [
            'association_operation_id' => $operation ?? (string) Str::ulid(),
            'receipt_id' => $receiptId,
            'payment_id' => $paymentId,
        ];
    }

    private function retirementPayload(): array
    {
        return [
            'retirement_operation_id' => (string) Str::ulid(),
            'retired_from' => now()->addDay()->toDateString(),
            'retirement_reason' => 'Account closed',
        ];
    }

    private function invalidationPayload(): array
    {
        return [
            'invalidation_operation_id' => (string) Str::ulid(),
            'invalidation_reason' => 'Receipt correction',
        ];
    }

    private function cancellationPayload(): array
    {
        return [
            'cancellation_operation_id' => (string) Str::ulid(),
            'cancellation_reason' => 'Attribution correction',
        ];
    }

    private function payment(string $tenantId, User $actor, string $customerId, string $amount, string $currency = 'SAR'): string
    {
        return app(RecordPaymentAction::class)->execute($tenantId, $actor, [
            'payment_operation_id' => (string) Str::ulid(),
            'customer_id' => $customerId,
            'amount' => $amount,
            'currency' => $currency,
            'received_on' => now()->toDateString(),
        ]);
    }

    private function context(): array
    {
        $actor = $this->createActiveUser(['role' => User::ROLE_ADMINISTRATOR]);
        $tenantId = (string) TenantUser::query()->where('user_id', $actor->id)->value('tenant_id');
        $customer = $this->createIntegrityCustomer($tenantId, $actor->id);

        return [$actor, $tenantId, (string) $customer->id];
    }

    private function assertReceiptProjection(array $receipt): void
    {
        foreach ([
            'tenant_id',
            'receipt_operation_id',
            'invalidation_operation_id',
            'accounting_status',
            'is_posted',
            'is_reconciled',
            'gl_cash_account',
            'recognized_revenue',
        ] as $field) {
            self::assertArrayNotHasKey($field, $receipt);
        }
    }

    private function assertAssociationProjection(array $association): void
    {
        foreach ([
            'tenant_id',
            'association_operation_id',
            'cancellation_operation_id',
            'accounting_status',
            'is_posted',
            'is_reconciled',
            'gl_cash_account',
            'recognized_revenue',
        ] as $field) {
            self::assertArrayNotHasKey($field, $association);
        }
    }
}
