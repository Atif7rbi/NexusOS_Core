<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Models\User;
use App\Modules\Payments\Exceptions\PaymentsConflict;
use App\Modules\Payments\Exceptions\PaymentsValidationFailed;
use App\Modules\Payments\Support\PaymentsTransaction;
use App\Modules\Payments\ValueObjects\PaymentAmount;
use App\Modules\Receivables\Support\ReceivablesAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecordPaymentAction
{
    public function __construct(
        private readonly PaymentsTransaction $tx,
        private readonly ReceivablesAuthorization $auth,
    ) {}

    public function execute(string $tenantId, User $actor, array $input): string
    {
        $facts = $this->canonicalize($input);
        $this->auth->authorize($tenantId, $actor);

        return $this->tx->run(function () use ($tenantId, $actor, $facts): string {
            $this->auth->authorizeTransactional($tenantId, $actor);
            $existing = DB::table('payments')->where('tenant_id', $tenantId)->where('payment_operation_id', $facts['payment_operation_id'])->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->customer_id !== $facts['customer_id'] || (string) $existing->amount !== $facts['amount'] || $existing->currency !== $facts['currency'] || (string) $existing->received_on !== $facts['received_on']) {
                    throw new PaymentsConflict('Payment operation identity was reused with different facts.');
                }

                return (string) $existing->id;
            }

            $id = (string) Str::ulid();
            $now = now();
            DB::table('payments')->insert([...$facts, 'id' => $id, 'tenant_id' => $tenantId, 'status' => 'received', 'received_by' => $actor->id, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

            return $id;
        });
    }

    private function canonicalize(array $input): array
    {
        $amount = (string) PaymentAmount::of($input['amount'] ?? '');
        $operation = (string) ($input['payment_operation_id'] ?? '');
        if (!Str::isUlid($operation)) {
            throw new PaymentsValidationFailed('payment_operation_id must be a caller-supplied ULID.');
        }
        $currency = (string) ($input['currency'] ?? '');
        if (!in_array($currency, ['SAR', 'USD'], true)) {
            throw new PaymentsValidationFailed('currency must be SAR or USD.');
        }
        $receivedOn = (string) ($input['received_on'] ?? '');
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $receivedOn, 'UTC');
        if ($date === false || $date->format('Y-m-d') !== $receivedOn || $date->isFuture()) {
            throw new PaymentsValidationFailed('received_on must be a non-future YYYY-MM-DD date.');
        }
        $customer = (string) ($input['customer_id'] ?? '');
        if ($customer === '') {
            throw new PaymentsValidationFailed('customer_id is required.');
        }

        return ['customer_id' => $customer, 'payment_operation_id' => $operation, 'amount' => $amount, 'currency' => $currency, 'received_on' => $receivedOn, 'method' => $input['method'] ?? null, 'reference' => $input['reference'] ?? null];
    }
}
