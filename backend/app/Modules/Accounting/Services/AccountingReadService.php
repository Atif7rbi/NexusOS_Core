<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class AccountingReadService
{
    public function settings(string $tenantId): array
    {
        $row = DB::table('accounting_settings')->where('tenant_id', $tenantId)->first();

        return $this->serializeSettings($this->found($row));
    }

    public function accounts(string $tenantId, array $filters): LengthAwarePaginator
    {
        $query = DB::table('accounts')->where('tenant_id', $tenantId);
        foreach (['status', 'kind', 'account_type', 'classification', 'parent_id'] as $filter) {
            if (array_key_exists($filter, $filters) && $filters[$filter] !== null) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(fn ($q) => $q->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
        }

        return $query->orderBy('code')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 20))->through(fn (object $row): array => $this->serializeAccount($row));
    }

    public function account(string $tenantId, string $id): array
    {
        return $this->serializeAccount($this->found(DB::table('accounts')->where('tenant_id', $tenantId)->where('id', $id)->first()));
    }

    public function periods(string $tenantId, array $filters): LengthAwarePaginator
    {
        $query = DB::table('accounting_periods')->where('tenant_id', $tenantId);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('start_date')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 20))->through(fn (object $row): array => $this->serializePeriod($row));
    }

    public function period(string $tenantId, string $id): array
    {
        return $this->serializePeriod($this->found(DB::table('accounting_periods')->where('tenant_id', $tenantId)->where('id', $id)->first()));
    }

    public function journals(string $tenantId, array $filters): LengthAwarePaginator
    {
        $query = DB::table('journal_entries')->where('tenant_id', $tenantId);
        foreach (['status', 'origin', 'journal_number'] as $filter) {
            if (! empty($filters[$filter])) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (! empty($filters['date_from'])) {
            $query->where('entry_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('entry_date', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('entry_date')->orderByRaw('journal_sequence_number DESC NULLS LAST')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))->through(fn (object $row): array => $this->serializeJournal($row));
    }

    public function journal(string $tenantId, string $id): array
    {
        $journal = $this->found(DB::table('journal_entries')->where('tenant_id', $tenantId)->where('id', $id)->first());
        $lines = DB::table('journal_lines')->where('tenant_id', $tenantId)->where('journal_entry_id', $id)->orderBy('line_number')->get();

        return $this->serializeJournal($journal) + ['lines' => $lines->map(fn (object $line): array => $this->serializeLine($line))->all()];
    }

    public function openingBalances(string $tenantId, array $filters): LengthAwarePaginator
    {
        $query = DB::table('opening_balance_operations')->where('tenant_id', $tenantId);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['effect_state'])) {
            $query->where('effect_state', $filters['effect_state']);
        }

        return $query->orderByDesc('accounting_date')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 20))
            ->through(fn (object $row): array => $this->serializeOpening($row));
    }

    public function openingBalance(string $tenantId, string $id): array
    {
        $operation = $this->found(DB::table('opening_balance_operations')->where('tenant_id', $tenantId)->where('id', $id)->first());
        $journal = $this->journal($tenantId, $operation->journal_entry_id);

        return $this->serializeOpening($operation) + ['root_journal' => $journal];
    }

    private function found(?object $row): object
    {
        if ($row === null) {
            throw (new ModelNotFoundException)->setModel('Accounting resource');
        }

        return $row;
    }

    private function serializeSettings(object $row): array
    {
        return ['settings_id' => $row->id, 'tenant_id' => $row->tenant_id, 'ledger_currency' => $row->ledger_currency, 'activated_by' => (int) $row->activated_by, 'activated_at' => $this->timestamp($row->activated_at)];
    }

    private function serializeAccount(object $row): array
    {
        return ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'description' => $row->description, 'kind' => $row->kind, 'account_type' => $row->account_type, 'classification' => $row->classification, 'parent_id' => $row->parent_id, 'status' => $row->status, 'archived_at' => $this->timestamp($row->archived_at), 'archived_by' => $row->archived_by === null ? null : (int) $row->archived_by, 'restored_at' => $this->timestamp($row->restored_at), 'restored_by' => $row->restored_by === null ? null : (int) $row->restored_by, 'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at)];
    }

    private function serializePeriod(object $row): array
    {
        return ['id' => $row->id, 'start_date' => (string) $row->start_date, 'end_date' => (string) $row->end_date, 'status' => $row->status, 'closed_at' => $this->timestamp($row->closed_at), 'closed_by' => $row->closed_by === null ? null : (int) $row->closed_by, 'reopened_at' => $this->timestamp($row->reopened_at), 'reopened_by' => $row->reopened_by === null ? null : (int) $row->reopened_by, 'reopen_reason' => $row->reopen_reason, 'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at)];
    }

    private function serializeJournal(object $row): array
    {
        return ['id' => $row->id, 'accounting_period_id' => $row->accounting_period_id, 'entry_date' => (string) $row->entry_date, 'description' => $row->description, 'status' => $row->status, 'origin' => $row->origin, 'source_type' => $row->source_type, 'source_id' => $row->source_id, 'journal_number' => $row->journal_number, 'journal_number_year' => $row->journal_number_year === null ? null : (int) $row->journal_number_year, 'journal_sequence_number' => $row->journal_sequence_number === null ? null : (int) $row->journal_sequence_number, 'reverses_journal_entry_id' => $row->reverses_journal_entry_id, 'reversal_reason' => $row->reversal_reason, 'created_by' => (int) $row->created_by, 'posted_by' => $row->posted_by === null ? null : (int) $row->posted_by, 'created_at' => $this->timestamp($row->created_at), 'posted_at' => $this->timestamp($row->posted_at)];
    }

    private function serializeLine(object $row): array
    {
        return ['line_number' => (int) $row->line_number, 'account_id' => $row->account_id, 'debit' => (string) $row->debit, 'credit' => (string) $row->credit, 'memo' => $row->memo];
    }

    private function serializeOpening(object $row): array
    {
        return ['operation_id' => $row->id, 'status' => $row->status, 'effect_state' => $row->effect_state, 'accounting_date' => (string) $row->accounting_date, 'journal_entry_id' => $row->journal_entry_id, 'latest_effect_journal_entry_id' => $row->latest_effect_journal_entry_id, 'posted_at' => $this->timestamp($row->posted_at), 'effect_updated_at' => $this->timestamp($row->effect_updated_at), 'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at)];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}
