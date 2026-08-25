<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\PostedJournalResult;
use App\Modules\Accounting\Exceptions\AccountingConflict;
use Illuminate\Support\Facades\DB;

final class BusinessPostingCanonicalResolver
{
    public function resolve(BusinessPostingRequest $request): PostedJournalResult
    {
        $existing = DB::table('journal_entries')
            ->where('tenant_id', $request->tenantId)
            ->where('origin', 'business')
            ->where('source_type', $request->sourceType)
            ->where('source_id', $request->sourceId)
            ->first();

        if ($existing === null) {
            throw new AccountingConflict('Business source outcome is not available.');
        }

        $lines = DB::table('journal_lines')
            ->where('tenant_id', $request->tenantId)
            ->where('journal_entry_id', $existing->id)
            ->orderBy('line_number')
            ->get();
        $same = $existing->status === 'posted'
            && $existing->entry_date === $request->entryDate
            && $existing->description === $request->description
            && $lines->count() === count($request->lines);
        foreach ($request->lines as $i => $expected) {
            $actual = $lines[$i] ?? null;
            $same = $same
                && $actual !== null
                && $actual->account_id === $expected->accountId
                && (string) $actual->debit === (string) $expected->debit
                && (string) $actual->credit === (string) $expected->credit
                && $actual->memo === $expected->memo;
        }
        if (! $same) {
            throw new AccountingConflict('Business source identity was reused with different accounting facts.');
        }

        return new PostedJournalResult($existing->id, $existing->journal_number, true);
    }
}
