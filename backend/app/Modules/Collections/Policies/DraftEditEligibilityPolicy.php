<?php

declare(strict_types=1);

namespace App\Modules\Collections\Policies;

use App\Modules\Collections\Enums\DerivedScheduleState;
use App\Modules\Collections\Exceptions\ContractNotEligibleForDraftEditException;
use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Models\Contract;

/**
 * Contract lifecycle matrix, DDL spec section 29:
 * `draft` + absent/draft schedule -> Draft Edit: Yes. Every other
 * combination is rejected.
 */
final class DraftEditEligibilityPolicy
{
    public function assert(Contract $contract, DerivedScheduleState $state): void
    {
        if ($contract->status !== ContractStatus::Draft) {
            throw new ContractNotEligibleForDraftEditException();
        }

        if (! in_array($state, [DerivedScheduleState::Absent, DerivedScheduleState::Draft], true)) {
            throw new ContractNotEligibleForDraftEditException();
        }
    }
}
