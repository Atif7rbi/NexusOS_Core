<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\PostedJournalResult;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Support\AccountingAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BusinessPostingService implements BusinessPostingServiceInterface
{
    public function __construct(
        private readonly PostingEngine $posting,
        private readonly AccountingAuthorization $auth,
        private readonly BusinessPostingCanonicalResolver $canonical,
    ) {}

    public function post(BusinessPostingRequest $request): PostedJournalResult
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Business posting requires a caller-owned transaction.');
        }
        $actor = User::query()->find($request->actorId) ?? throw new AccountingValidationFailed('Actor was not found.');
        $this->auth->authorizeTransactional($request->tenantId, $actor, 'post_journal');
        $existing = DB::table('journal_entries')->where('tenant_id', $request->tenantId)->where('origin', 'business')->where('source_type', $request->sourceType)->where('source_id', $request->sourceId)->first();
        if ($existing !== null) {
            return $this->canonical->resolve($request);
        }
        if (! DB::table('accounting_source_types')->where('origin', 'business')->where('key', $request->sourceType)->exists()) {
            throw new AccountingValidationFailed('Business source type is not registered.');
        }
        $id = (string) Str::ulid();
        $at = now();
        DB::table('journal_entries')->insert(['id' => $id, 'tenant_id' => $request->tenantId, 'entry_date' => $request->entryDate, 'description' => $request->description, 'status' => 'draft', 'origin' => 'business', 'source_type' => $request->sourceType, 'source_id' => $request->sourceId, 'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => $at, 'updated_at' => $at]);
        foreach (array_values($request->lines) as $i => $line) {
            DB::table('journal_lines')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $request->tenantId, 'journal_entry_id' => $id, 'line_number' => $i + 1, 'account_id' => $line->accountId, 'debit' => (string) $line->debit, 'credit' => (string) $line->credit, 'memo' => $line->memo, 'created_at' => $at, 'updated_at' => $at]);
        }

        return $this->posting->post($request->tenantId, $id, $actor);
    }
}
