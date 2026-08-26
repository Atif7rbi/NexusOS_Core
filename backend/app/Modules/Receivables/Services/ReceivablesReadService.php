<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ReceivablesReadService
{
    public function index(string $tenantId, array $filters): LengthAwarePaginator
    {
        $query = DB::table('receivables')->where('tenant_id', $tenantId);
        foreach (['status', 'customer_id', 'contract_id', 'collection_id', 'currency'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['due_from'])) {
            $query->where('due_date', '>=', $filters['due_from']);
        }
        if (! empty($filters['due_to'])) {
            $query->where('due_date', '<=', $filters['due_to']);
        }

        return $query->orderBy('due_date')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 20))
            ->through(fn (object $row): array => $this->serialize($row));
    }

    public function find(string $tenantId, string $id): array
    {
        $row = DB::table('receivables')->where('tenant_id', $tenantId)->where('id', $id)->first();
        if ($row === null) {
            throw (new ModelNotFoundException)->setModel('Receivable');
        }

        return $this->serialize($row);
    }

    private function serialize(object $row): array
    {
        return [
            'id' => $row->id,
            'customer_id' => $row->customer_id,
            'contract_id' => $row->contract_id,
            'collection_id' => $row->collection_id,
            'currency' => $row->currency,
            'recognized_amount' => (string) $row->recognized_amount,
            'due_date' => (string) $row->due_date,
            'status' => $row->status,
            'recognized_at' => CarbonImmutable::parse((string) $row->recognized_at)->toISOString(),
            'recognized_by' => (int) $row->recognized_by,
            'cancelled_at' => $row->cancelled_at === null ? null : CarbonImmutable::parse((string) $row->cancelled_at)->toISOString(),
            'cancelled_by' => $row->cancelled_by === null ? null : (int) $row->cancelled_by,
            'cancellation_reason' => $row->cancellation_reason,
            'created_at' => CarbonImmutable::parse((string) $row->created_at)->toISOString(),
            'updated_at' => CarbonImmutable::parse((string) $row->updated_at)->toISOString(),
        ];
    }
}
