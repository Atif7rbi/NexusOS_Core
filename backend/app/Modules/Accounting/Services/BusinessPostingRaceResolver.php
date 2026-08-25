<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\DTOs\BusinessPostingRequest;
use App\Modules\Accounting\DTOs\PostedJournalResult;
use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\Support\AccountingAuthorization;
use App\Modules\Accounting\Support\AccountingTransaction;
use Illuminate\Support\Facades\DB;

final class BusinessPostingRaceResolver
{
    public function __construct(
        private readonly AccountingTransaction $tx,
        private readonly AccountingAuthorization $auth,
        private readonly BusinessPostingCanonicalResolver $canonical,
    ) {}

    public function resolveAfterRollback(BusinessPostingRequest $request): PostedJournalResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Business source race resolution requires a fresh transaction after rollback.');
        }

        $actor = User::query()->find($request->actorId)
            ?? throw new AccountingValidationFailed('Actor was not found.');
        $this->auth->authorize($request->tenantId, $actor, 'post_journal');

        return $this->tx->run(function () use ($request, $actor): PostedJournalResult {
            $this->auth->authorizeTransactional($request->tenantId, $actor, 'post_journal');

            return $this->canonical->resolve($request);
        });
    }
}
