<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Actions;

use App\Models\User;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceValidationFailed;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceFacts;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceTransaction;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ApproveReceivingAccount
{
    public function __construct(private readonly ReceiptEvidenceTransaction $tx, private readonly ReceivablesAuthorization $auth) {}

    public function execute(string $tenantId, User $actor, array $input): string
    {
        $facts = $this->facts($input);
        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use ($tenantId, $actor, $facts): string {
            $this->auth->authorizeTransactional($tenantId, $actor);
            $existing = DB::table('approved_receiving_accounts')->where('tenant_id', $tenantId)->where('receiving_account_operation_id', $facts['receiving_account_operation_id'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($this->matches($existing, $facts)) return (string) $existing->id;
                throw new ReceiptEvidenceConflict('Receiving account operation identity was reused with different facts.');
            }
            $identity = DB::table('approved_receiving_accounts')->where('tenant_id', $tenantId)->where('institution_identifier', $facts['institution_identifier'])->where('account_identity', $facts['account_identity'])->lockForUpdate()->first();
            if ($identity !== null) throw new ReceiptEvidenceConflict('Receiving account identity is already registered.');
            $id = (string) Str::ulid(); $now = now();
            DB::table('approved_receiving_accounts')->insert([...$facts, 'id' => $id, 'tenant_id' => $tenantId, 'status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

            return $id;
        });
    }

    private function facts(array $input): array
    {
        $institution = trim((string) ($input['institution_identifier'] ?? ''));
        $identity = trim((string) ($input['account_identity'] ?? ''));
        $masked = trim((string) ($input['masked_account_identity'] ?? ''));
        if ($institution === '' || $identity === '' || $masked === '') throw new ReceiptEvidenceValidationFailed('institution_identifier, account_identity, and masked_account_identity are required.');

        return ['receiving_account_operation_id' => ReceiptEvidenceFacts::operation($input, 'receiving_account_operation_id'), 'institution_identifier' => $institution, 'account_identity' => $identity, 'masked_account_identity' => $masked, 'valid_from' => ReceiptEvidenceFacts::date($input, 'valid_from')];
    }

    private function matches(object $row, array $facts): bool
    {
        return $row->institution_identifier === $facts['institution_identifier'] && $row->account_identity === $facts['account_identity'] && $row->masked_account_identity === $facts['masked_account_identity'] && (string) $row->valid_from === $facts['valid_from'];
    }
}
