<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Accounting\Actions\ActivateAccountingAction;
use App\Modules\Accounting\Actions\ManageAccountAction;
use App\Modules\Accounting\Actions\ManageAccountingPeriodAction;
use App\Modules\Accounting\Actions\ManageManualJournalAction;
use App\Modules\Accounting\Actions\ManageOpeningBalanceAction;
use App\Modules\Accounting\Actions\ReverseJournalAction;
use App\Modules\Accounting\Contracts\BusinessPostingServiceInterface;
use App\Modules\Accounting\Services\BusinessPostingRaceResolver;
use App\Modules\Accounting\Services\BusinessPostingService;
use App\Modules\Accounting\Services\PostingEngine;
use Tests\TestCase;

final class AccountingApplicationStructureTest extends TestCase
{
    public function test_phase_two_services_remain_resolvable_after_http_boundary_is_added(): void
    {
        foreach ([ActivateAccountingAction::class, ManageAccountAction::class, ManageAccountingPeriodAction::class, ManageManualJournalAction::class, ManageOpeningBalanceAction::class, ReverseJournalAction::class, PostingEngine::class, BusinessPostingRaceResolver::class] as $class) {
            self::assertInstanceOf($class, $this->app->make($class));
        }
        self::assertInstanceOf(BusinessPostingService::class, $this->app->make(BusinessPostingServiceInterface::class));
    }
}
