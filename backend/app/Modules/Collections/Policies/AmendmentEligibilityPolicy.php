<?php

declare(strict_types=1);

namespace App\Modules\Collections\Policies;

use App\Modules\Collections\Enums\DerivedScheduleState;
use App\Modules\Collections\Exceptions\ContractNotEligibleForAmendmentException;
use App\Modules\Collections\Exceptions\NoActiveScheduleToAmendException;
use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Models\Contract;

/**
 * Contract lifecycle matrix, DDL spec section 29:
 *
 * - `draft` + absent/draft schedule -> Amend: No (must finalize first)
 * - `draft` + scheduled schedule    -> Amend: Yes
 * - `active`                        -> Amend: Yes
 * - `completed`                     -> Amend: Yes
 * - `cancelled`                     -> Amend: No
 *
 * Section 25 lists "Validate lifecycle" and "Validate amendment
 * eligibility" as two distinct steps: the Contract-status check, and the
 * requirement that an active Scheduled schedule actually exists to amend.
 */
final class AmendmentEligibilityPolicy
{
    public function assert(Contract $contract, DerivedScheduleState $state): void
    {
        $lifecycleAllows = match ($contract->status) {
            ContractStatus::Draft => $state === DerivedScheduleState::Scheduled,
            ContractStatus::Active, ContractStatus::Completed => true,
            ContractStatus::Cancelled => false,
        };

        if (! $lifecycleAllows) {
            throw new ContractNotEligibleForAmendmentException();
        }

        if ($state !== DerivedScheduleState::Scheduled) {
            throw new NoActiveScheduleToAmendException();
        }
    }
}
