<?php

namespace Tests\Unit\Collections\Policies;

use App\Modules\Collections\Enums\DerivedScheduleState;
use App\Modules\Collections\Exceptions\ContractNotEligibleForAmendmentException;
use App\Modules\Collections\Exceptions\NoActiveScheduleToAmendException;
use App\Modules\Collections\Policies\AmendmentEligibilityPolicy;
use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Models\Contract;
use PHPUnit\Framework\TestCase;

class AmendmentEligibilityPolicyTest extends TestCase
{
    private AmendmentEligibilityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new AmendmentEligibilityPolicy();
    }

    public function test_draft_contract_with_scheduled_schedule_is_allowed(): void
    {
        $this->policy->assert($this->contract(ContractStatus::Draft), DerivedScheduleState::Scheduled);

        $this->addToAssertionCount(1);
    }

    public function test_draft_contract_with_draft_schedule_is_rejected(): void
    {
        $this->expectException(ContractNotEligibleForAmendmentException::class);

        $this->policy->assert($this->contract(ContractStatus::Draft), DerivedScheduleState::Draft);
    }

    public function test_draft_contract_with_absent_schedule_is_rejected(): void
    {
        $this->expectException(ContractNotEligibleForAmendmentException::class);

        $this->policy->assert($this->contract(ContractStatus::Draft), DerivedScheduleState::Absent);
    }

    public function test_active_contract_with_scheduled_schedule_is_allowed(): void
    {
        $this->policy->assert($this->contract(ContractStatus::Active), DerivedScheduleState::Scheduled);

        $this->addToAssertionCount(1);
    }

    public function test_completed_contract_with_scheduled_schedule_is_allowed(): void
    {
        $this->policy->assert($this->contract(ContractStatus::Completed), DerivedScheduleState::Scheduled);

        $this->addToAssertionCount(1);
    }

    public function test_active_contract_without_scheduled_state_is_rejected(): void
    {
        // Defensive: should not occur in practice, but the schedule-state
        // check is a distinct validation step per section 25.
        $this->expectException(NoActiveScheduleToAmendException::class);

        $this->policy->assert($this->contract(ContractStatus::Active), DerivedScheduleState::Invalid);
    }

    public function test_cancelled_contract_is_rejected(): void
    {
        $this->expectException(ContractNotEligibleForAmendmentException::class);

        $this->policy->assert($this->contract(ContractStatus::Cancelled), DerivedScheduleState::Scheduled);
    }

    private function contract(ContractStatus $status): Contract
    {
        $contract = new Contract();
        $contract->status = $status;

        return $contract;
    }
}
