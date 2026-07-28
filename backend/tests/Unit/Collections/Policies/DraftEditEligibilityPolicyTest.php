<?php

namespace Tests\Unit\Collections\Policies;

use App\Modules\Collections\Enums\DerivedScheduleState;
use App\Modules\Collections\Exceptions\ContractNotEligibleForDraftEditException;
use App\Modules\Collections\Policies\DraftEditEligibilityPolicy;
use App\Modules\Contracts\Enums\ContractStatus;
use App\Modules\Contracts\Models\Contract;
use PHPUnit\Framework\TestCase;

class DraftEditEligibilityPolicyTest extends TestCase
{
    private DraftEditEligibilityPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new DraftEditEligibilityPolicy();
    }

    public function test_draft_contract_with_absent_schedule_is_allowed(): void
    {
        $this->policy->assert($this->contract(ContractStatus::Draft), DerivedScheduleState::Absent);

        $this->addToAssertionCount(1);
    }

    public function test_draft_contract_with_draft_schedule_is_allowed(): void
    {
        $this->policy->assert($this->contract(ContractStatus::Draft), DerivedScheduleState::Draft);

        $this->addToAssertionCount(1);
    }

    public function test_draft_contract_with_scheduled_schedule_is_rejected(): void
    {
        $this->expectException(ContractNotEligibleForDraftEditException::class);

        $this->policy->assert($this->contract(ContractStatus::Draft), DerivedScheduleState::Scheduled);
    }

    public function test_active_contract_is_rejected(): void
    {
        $this->expectException(ContractNotEligibleForDraftEditException::class);

        $this->policy->assert($this->contract(ContractStatus::Active), DerivedScheduleState::Absent);
    }

    public function test_completed_contract_is_rejected(): void
    {
        $this->expectException(ContractNotEligibleForDraftEditException::class);

        $this->policy->assert($this->contract(ContractStatus::Completed), DerivedScheduleState::Absent);
    }

    public function test_cancelled_contract_is_rejected(): void
    {
        $this->expectException(ContractNotEligibleForDraftEditException::class);

        $this->policy->assert($this->contract(ContractStatus::Cancelled), DerivedScheduleState::Absent);
    }

    private function contract(ContractStatus $status): Contract
    {
        $contract = new Contract();
        $contract->status = $status;

        return $contract;
    }
}
