<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Actions;

use App\Models\User;
use App\Modules\ReceiptEvidence\Exceptions\ReceiptEvidenceConflict;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceFacts;
use App\Modules\ReceiptEvidence\Support\ReceiptEvidenceTransaction;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class RetireReceivingAccount
{
    public function __construct(private readonly ReceiptEvidenceTransaction $tx, private readonly ReceivablesAuthorization $auth) {}

    public function execute(string $tenantId, string $accountId, User $actor, array $input): void
    {
        $facts = ['retirement_operation_id' => ReceiptEvidenceFacts::operation($input, 'retirement_operation_id'), 'retired_from' => ReceiptEvidenceFacts::date($input, 'retired_from'), 'retirement_reason' => ReceiptEvidenceFacts::reason($input, 'retirement_reason')];
        $this->auth->authorize($tenantId, $actor);
        $this->tx->run(function () use ($tenantId, $accountId, $actor, $facts): void {
            $this->auth->authorizeTransactional($tenantId, $actor);
            $account = DB::table('approved_receiving_accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->lockForUpdate()->first();
            if ($account === null) throw (new ModelNotFoundException)->setModel('ApprovedReceivingAccount');
            if ($account->status === 'retired') {
                if ($account->retirement_operation_id === $facts['retirement_operation_id'] && (string) $account->retired_from === $facts['retired_from'] && $account->retirement_reason === $facts['retirement_reason']) return;
                throw new ReceiptEvidenceConflict('Receiving account is already retired by a different terminal operation.');
            }
            if ($facts['retired_from'] <= (string) $account->valid_from) throw new ReceiptEvidenceConflict('retired_from must be after valid_from.');
            $operation = DB::table('approved_receiving_accounts')->where('tenant_id', $tenantId)->where('retirement_operation_id', $facts['retirement_operation_id'])->lockForUpdate()->first();
            if ($operation !== null) throw new ReceiptEvidenceConflict('Retirement operation identity was reused with different facts.');
            $now = now();
            DB::table('approved_receiving_accounts')->where('tenant_id', $tenantId)->where('id', $accountId)->update([...$facts, 'status' => 'retired', 'retired_by' => $actor->id, 'retired_at' => $now, 'updated_at' => $now]);
        });
    }
}
