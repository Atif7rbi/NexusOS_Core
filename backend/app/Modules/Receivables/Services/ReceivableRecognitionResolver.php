<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Services;

use App\Modules\Receivables\Exceptions\ReceivablesConflict;
use App\Modules\Receivables\ValueObjects\RecognizedAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReceivableRecognitionResolver
{
    public function resolve(string $tenantId, string $operationId, array $facts): string
    {
        $existing = DB::table('receivables')
            ->where('tenant_id', $tenantId)
            ->where('recognition_operation_id', $operationId)
            ->first();
        if ($existing === null || ! $this->matches($existing, $facts)) {
            throw new ReceivablesConflict('Recognition operation identity was reused with different facts.');
        }

        return (string) $existing->id;
    }

    private function matches(object $row, array $facts): bool
    {
        return $row->customer_id === $facts['customer_id']
            && $row->contract_id === ($facts['contract_id'] ?? null)
            && $row->collection_id === ($facts['collection_id'] ?? null)
            && $row->currency === $facts['currency']
            && (string) $row->recognized_amount === (string) RecognizedAmount::of($facts['recognized_amount'])
            && (string) $row->due_date === $facts['due_date']
            && CarbonImmutable::parse((string) $row->recognized_at)->equalTo($facts['recognized_at']);
    }
}
