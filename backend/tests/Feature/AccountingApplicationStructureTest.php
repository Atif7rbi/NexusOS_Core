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
use App\Modules\Accounting\Services\BusinessPostingService;
use App\Modules\Accounting\Services\PostingEngine;
use Tests\TestCase;

final class AccountingApplicationStructureTest extends TestCase
{
    public function test_phase_two_services_resolve_without_an_http_boundary():void
    {
        foreach([ActivateAccountingAction::class,ManageAccountAction::class,ManageAccountingPeriodAction::class,ManageManualJournalAction::class,ManageOpeningBalanceAction::class,ReverseJournalAction::class,PostingEngine::class]as$class){self::assertInstanceOf($class,$this->app->make($class));}
        self::assertInstanceOf(BusinessPostingService::class,$this->app->make(BusinessPostingServiceInterface::class));
    }
    public function test_phase_two_does_not_add_routes_or_controllers():void
    {
        self::assertDirectoryDoesNotExist(app_path('Modules/Accounting/Controllers'));
        self::assertDirectoryDoesNotExist(app_path('Modules/Accounting/Http'));
    }
}
