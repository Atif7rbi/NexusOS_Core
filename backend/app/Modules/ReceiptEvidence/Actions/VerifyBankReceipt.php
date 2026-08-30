<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Actions;

use App\Models\User;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceValidationFailed;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceFacts;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceTransaction;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VerifyBankReceipt
{
    public function __construct(private readonly ReceiptEvidenceTransaction $tx, private readonly ReceivablesAuthorization $auth) {}

    public function execute(string $tenantId, User $actor, array $input): string
    {
        $facts = $this->facts($input);
        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use ($tenantId, $actor, $facts): string {
            $this->auth->authorizeTransactional($tenantId, $actor);
            $account = DB::table('approved_receiving_accounts')->where('tenant_id', $tenantId)->where('id', $facts['receiving_account_id'])->lockForUpdate()->first();
            if ($account === null) throw (new ModelNotFoundException)->setModel('ApprovedReceivingAccount');
            if ((string) $account->valid_from > $facts['control_date'] || ($account->retired_from !== null && $facts['control_date'] >= (string) $account->retired_from)) throw new ReceiptEvidenceConflict('Receiving account was not approved at receipt control date.');
            $byOperation = DB::table('bank_receipt_evidence')->where('tenant_id', $tenantId)->where('receipt_operation_id', $facts['receipt_operation_id'])->lockForUpdate()->first();
            if ($byOperation !== null) {
                if ($this->matches($byOperation, $facts)) return (string) $byOperation->id;
                throw new ReceiptEvidenceConflict('Receipt operation identity was reused with different facts.');
            }
            $bySource = DB::table('bank_receipt_evidence')->where('tenant_id', $tenantId)->where('receiving_account_id', $facts['receiving_account_id'])->where('source_identity_kind', $facts['source_identity_kind'])->where('source_identity_version', $facts['source_identity_version'])->where('source_identity', $facts['source_identity'])->where('status', 'effective')->lockForUpdate()->first();
            if ($bySource !== null) {
                if ($this->matches($bySource, $facts)) return (string) $bySource->id;
                throw new ReceiptEvidenceConflict('Bank receipt source identity was reused with different facts.');
            }
            if ($facts['replaces_receipt_id'] !== null) {
                $original = DB::table('bank_receipt_evidence')->where('tenant_id', $tenantId)->where('id', $facts['replaces_receipt_id'])->lockForUpdate()->first();
                if ($original === null || $original->status !== 'invalidated') throw new ReceiptEvidenceConflict('Replacement receipt requires an invalidated original receipt.');
            }
            $id = (string) Str::ulid(); $now = now();
            DB::table('bank_receipt_evidence')->insert([...$facts, 'id' => $id, 'tenant_id' => $tenantId, 'channel' => 'bank_transfer', 'verification_method' => 'manual_receiving_side_bank_evidence', 'verified_by' => $actor->id, 'verified_at' => $now, 'status' => 'effective', 'created_at' => $now, 'updated_at' => $now]);

            return $id;
        });
    }

    private function facts(array $input): array
    {
        $account = (string) ($input['receiving_account_id'] ?? ''); $kind = (string) ($input['source_identity_kind'] ?? ''); $source = trim((string) ($input['source_identity'] ?? '')); $reference = trim((string) ($input['evidence_reference'] ?? ''));
        if ($account === '' || ! in_array($kind, ['bank_transaction_id', 'statement_line_fingerprint_v1'], true) || $source === '' || $reference === '') throw new ReceiptEvidenceValidationFailed('receiving_account_id, supported source identity, and evidence_reference are required.');
        $version = (int) ($input['source_identity_version'] ?? 0);
        if ($version < 1) throw new ReceiptEvidenceValidationFailed('source_identity_version must be positive.');

        return ['receipt_operation_id' => ReceiptEvidenceFacts::operation($input, 'receipt_operation_id'), 'receiving_account_id' => $account, 'source_identity_kind' => $kind, 'source_identity_version' => $version, 'source_identity' => $source, 'amount' => ReceiptEvidenceFacts::amount($input), 'currency' => ReceiptEvidenceFacts::currency($input), 'control_date' => ReceiptEvidenceFacts::date($input, 'control_date'), 'evidence_reference' => $reference, 'evidence_locator' => ($value = trim((string) ($input['evidence_locator'] ?? ''))) === '' ? null : $value, 'replaces_receipt_id' => ($replacement = (string) ($input['replaces_receipt_id'] ?? '')) === '' ? null : $replacement];
    }

    private function matches(object $row, array $facts): bool
    {
        return $row->receiving_account_id === $facts['receiving_account_id'] && $row->source_identity_kind === $facts['source_identity_kind'] && (int) $row->source_identity_version === $facts['source_identity_version'] && $row->source_identity === $facts['source_identity'] && (string) $row->amount === $facts['amount'] && $row->currency === $facts['currency'] && (string) $row->control_date === $facts['control_date'];
    }
}
