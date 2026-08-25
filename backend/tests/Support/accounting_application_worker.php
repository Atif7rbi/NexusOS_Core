<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\Actions\ManageOpeningBalanceAction;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Exceptions\AccountingConflict;
use App\Modules\Accounting\Services\BusinessPostingRaceResolver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

$payload = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.host' => $payload['database']['host'],
    'database.connections.pgsql.port' => $payload['database']['port'],
    'database.connections.pgsql.database' => $payload['database']['database'],
    'database.connections.pgsql.username' => $payload['database']['username'],
    'database.connections.pgsql.password' => $payload['database']['password'],
]);
DB::purge('pgsql');

try {
    if (($payload['pre_delay_ms'] ?? 0) > 0) {
        usleep((int) $payload['pre_delay_ms'] * 1000);
    }

    if ($payload['action'] === 'deactivate_membership') {
        DB::transaction(function () use ($payload): void {
            DB::table('tenant_users')
                ->where('tenant_id', $payload['tenant_id'])
                ->where('user_id', $payload['actor_id'])
                ->update(['status' => 'paused', 'updated_at' => now()]);
            usleep((int) $payload['hold_ms'] * 1000);
        });
        $result = [];
    } else {
        $actor = User::query()->findOrFail($payload['actor_id']);
        $result = match ($payload['action']) {
            'manual_post' => ['journal_id' => app(ManageManualJournalAction::class)
                ->post($payload['tenant_id'], $payload['journal_id'], $actor)->journalEntryId],
            'account_archive' => tap([], fn () => app(ManageAccountAction::class)
                ->archive($payload['tenant_id'], $payload['account_id'], $actor)),
            'period_boundaries' => tap([], fn () => app(ManageAccountingPeriodAction::class)
                ->changeBoundaries($payload['tenant_id'], $payload['period_id'], $actor, $payload['start_date'], $payload['end_date'])),
            'opening_update' => tap([], fn () => app(ManageOpeningBalanceAction::class)
                ->update($payload['tenant_id'], $payload['operation_id'], $actor, $payload['entry_date'], lines($payload['lines']))),
            'opening_delete' => tap([], fn () => app(ManageOpeningBalanceAction::class)
                ->delete($payload['tenant_id'], $payload['operation_id'], $actor)),
            'opening_post' => ['journal_id' => app(ManageOpeningBalanceAction::class)
                ->post($payload['tenant_id'], $payload['operation_id'], $actor)->journalEntryId],
            'opening_reverse' => ['journal_id' => app(ReverseJournalAction::class)
                ->execute($payload['tenant_id'], $payload['journal_id'], $actor, $payload['entry_date'], 'Concurrency correction')->journalEntryId],
            'business_post' => postBusiness($payload),
            default => throw new LogicException('Unknown worker action.'),
        };
    }

    echo json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}

/** @param array<int,array<string,mixed>> $values @return array<int,JournalLineData> */
function lines(array $values): array
{
    return array_map(
        fn (array $line): JournalLineData => new JournalLineData($line['account_id'], $line['debit'], $line['credit'], $line['memo'] ?? null),
        $values,
    );
}

/** @param array<string,mixed> $payload @return array<string,mixed> */
function postBusiness(array $payload): array
{
    $request = new BusinessPostingRequest(
        $payload['tenant_id'],
        $payload['actor_id'],
        $payload['source_type'],
        $payload['source_id'],
        'SAR',
        $payload['entry_date'],
        $payload['description'],
        lines($payload['lines']),
    );

    try {
        $posted = DB::transaction(fn () => app(BusinessPostingServiceInterface::class)->post($request));
    } catch (QueryException|AccountingConflict $exception) {
        $state = $exception instanceof QueryException ? (string) ($exception->errorInfo[0] ?? $exception->getCode()) : null;
        if ($exception instanceof QueryException && $state !== '23505') {
            throw $exception;
        }
        $posted = app(BusinessPostingRaceResolver::class)->resolveAfterRollback($request);
    }

    return [
        'journal_id' => $posted->journalEntryId,
        'journal_number' => $posted->journalNumber,
        'idempotent_replay' => $posted->idempotentReplay,
    ];
}
