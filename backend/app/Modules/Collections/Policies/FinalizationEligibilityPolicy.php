<?php

declare(strict_types=1);

namespace App\Modules\Collections\Policies;

use App\Modules\Collections\Enums\DerivedScheduleState;
use App\Modules\Collections\Exceptions\ContractNotEligibleForFinalizationException;
use App\Modules\Collections\Exceptions\ScheduleNotInDraftStateException;
use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Models\Contract;

/**
 * Contract lifecycle matrix, DDL spec section 29: Finalize is only allowed
 * while the Contract is draft. Section 24 additionally requires the
 * Derived Schedule State to be exactly `draft` before finalizing.
 */
final class FinalizationEligibilityPolicy
{
    public function assert(Contract $contract, DerivedScheduleState $state): void
    {
        if ($contract->status !== ContractStatus::Draft) {
            throw new ContractNotEligibleForFinalizationException();
        }

        if ($state !== DerivedScheduleState::Draft) {
            throw new ScheduleNotInDraftStateException();
        }
    }
}
