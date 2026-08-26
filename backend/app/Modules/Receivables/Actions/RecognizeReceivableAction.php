<?php

declare(strict_types=1);

namespace App\Modules\Receivables\Actions;

use App\Models\User;
use App\Modules\Receivables\Exceptions\ReceivablesConflict;
use App\Modules\Receivables\Exceptions\ReceivablesValidationFailed;
use App\Modules\Receivables\Services\ReceivableRecognitionResolver;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use App\Modules\Receivables\Support\ReceivablesTransaction;
use App\Modules\Receivables\ValueObjects\RecognizedAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecognizeReceivableAction
{
    public function __construct(private readonly ReceivablesTransaction $tx, private readonly ReceivablesAuthorization $auth, private readonly ReceivableRecognitionResolver $resolver) {}

    public function execute(string $tenantId, User $actor, array $input): string
    {
        $this->auth->authorize($tenantId, $actor);
        [$operationId, $facts] = $this->normalize($input);

        try {
            return $this->tx->run(fn (): string => $this->recognizeInTransaction($tenantId, $actor, $operationId, $facts));
        } catch (ReceivablesConflict $conflict) {
            return $this->resolver->resolve($tenantId, $operationId, $facts);
        }
    }

    public function executeInTransaction(string $tenantId, User $actor, array $input): string
    {
        [$operationId, $facts] = $this->normalize($input);

        return $this->recognizeInTransaction($tenantId, $actor, $operationId, $facts);
    }

    private function normalize(array $input): array
    {
        $amount = RecognizedAmount::of($input['recognized_amount'] ?? '');
        $currency = (string) ($input['currency'] ?? '');
        if (! in_array($currency, ['SAR', 'USD'], true)) {
            throw new ReceivablesValidationFailed('currency must be SAR or USD.');
        }
        $dueDate = $this->date((string) ($input['due_date'] ?? ''));
        $recognizedAt = $this->timestamp((string) ($input['recognized_at'] ?? ''), 'recognized_at');
        $customerId = (string) ($input['customer_id'] ?? '');
        $operationId = (string) ($input['recognition_operation_id'] ?? '');
        if ($customerId === '') {
            throw new ReceivablesValidationFailed('customer_id is required.');
        }
        if (! Str::isUlid($operationId)) {
            throw new ReceivablesValidationFailed('recognition_operation_id must be a caller-supplied ULID.');
        }

        $facts = [
            'customer_id' => $customerId,
            'contract_id' => $input['contract_id'] ?? null,
            'collection_id' => $input['collection_id'] ?? null,
            'currency' => $currency,
            'recognized_amount' => (string) $amount,
            'due_date' => $dueDate,
            'recognized_at' => $recognizedAt,
        ];

        return [$operationId, $facts];
    }

    public function recognizeInTransaction(string $tenantId, User $actor, string $operationId, array $facts): string
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Receivable recognition requires a caller-owned transaction.');
        }
        $this->auth->authorizeTransactional($tenantId, $actor);
        if (DB::table('receivables')->where('tenant_id', $tenantId)->where('recognition_operation_id', $operationId)->exists()) {
            return $this->resolver->resolve($tenantId, $operationId, $facts);
        }
        $id = (string) Str::ulid();
        $now = now();
        DB::table('receivables')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'recognition_operation_id' => $operationId,
            'customer_id' => $facts['customer_id'],
            'contract_id' => $facts['contract_id'],
            'collection_id' => $facts['collection_id'],
            'currency' => $facts['currency'],
            'recognized_amount' => $facts['recognized_amount'],
            'due_date' => $facts['due_date'],
            'status' => 'recognized',
            'recognized_at' => $facts['recognized_at'],
            'recognized_by' => $actor->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function date(string $value): string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            $date = false;
        }
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ReceivablesValidationFailed('due_date must be an explicit YYYY-MM-DD business date.');
        }

        return $value;
    }

    private function timestamp(string $value, string $field): CarbonImmutable
    {
        try {
            $timestamp = CarbonImmutable::createFromFormat(DATE_RFC3339, $value);
        } catch (\Throwable) {
            $timestamp = false;
        }
        if ($timestamp === false) {
            throw new ReceivablesValidationFailed("{$field} must be an RFC3339 timestamp.");
        }

        return $timestamp;
    }
}
