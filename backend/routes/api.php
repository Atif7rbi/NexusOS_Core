<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SystemSettingController;
use App\Modules\Accounting\Controllers\AccountingPeriodController;
use App\Modules\Accounting\Controllers\AccountingSettingsController;
use App\Modules\Accounting\Controllers\AccountController;
use App\Modules\Accounting\Controllers\JournalController;
use App\Modules\Accounting\Controllers\OpeningBalanceController;
use App\Modules\Collections\Controllers\CollectionScheduleController;
use App\Modules\Collections\Controllers\CollectionsIndexController;
use App\Modules\Contracts\Controllers\ContractController;
use App\Modules\Customers\Controllers\CustomerController;
use App\Modules\Leads\Controllers\LeadActivityController;
use App\Modules\Leads\Controllers\LeadController;
use App\Modules\Leads\Controllers\LeadFollowUpController;
use App\Modules\Projects\Controllers\ProjectController;
use App\Modules\Reservations\Controllers\ReservationController;
use App\Modules\Units\Controllers\UnitController;
use App\Modules\Users\Controllers\TenantUserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', 'tenant.active'])->group(function (): void {
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/system-settings', [SystemSettingController::class, 'show']);
    Route::put('/system-settings', [SystemSettingController::class, 'update']);

    Route::prefix('accounting')->name('accounting.')->group(function (): void {
        Route::post('/activation', [AccountingSettingsController::class, 'activate'])->name('activation.store');
        Route::get('/settings', [AccountingSettingsController::class, 'show'])->name('settings.show');

        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
        Route::patch('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
        Route::post('/accounts/{account}/archive', [AccountController::class, 'archive'])->name('accounts.archive');
        Route::post('/accounts/{account}/restore', [AccountController::class, 'restore'])->name('accounts.restore');

        Route::get('/periods', [AccountingPeriodController::class, 'index'])->name('periods.index');
        Route::post('/periods', [AccountingPeriodController::class, 'store'])->name('periods.store');
        Route::get('/periods/{period}', [AccountingPeriodController::class, 'show'])->name('periods.show');
        Route::patch('/periods/{period}', [AccountingPeriodController::class, 'update'])->name('periods.update');
        Route::post('/periods/{period}/close', [AccountingPeriodController::class, 'close'])->name('periods.close');
        Route::post('/periods/{period}/reopen', [AccountingPeriodController::class, 'reopen'])->name('periods.reopen');

        Route::get('/journals', [JournalController::class, 'index'])->name('journals.index');
        Route::post('/journals', [JournalController::class, 'store'])->name('journals.store');
        Route::get('/journals/{journal}', [JournalController::class, 'show'])->name('journals.show');
        Route::patch('/journals/{journal}', [JournalController::class, 'update'])->name('journals.update');
        Route::delete('/journals/{journal}', [JournalController::class, 'destroy'])->name('journals.destroy');
        Route::post('/journals/{journal}/post', [JournalController::class, 'post'])->name('journals.post');
        Route::post('/journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('journals.reverse');

        Route::get('/opening-balance', [OpeningBalanceController::class, 'index'])->name('opening-balance.index');
        Route::post('/opening-balance', [OpeningBalanceController::class, 'store'])->name('opening-balance.store');
        Route::get('/opening-balance/{operation}', [OpeningBalanceController::class, 'show'])->name('opening-balance.show');
        Route::patch('/opening-balance/{operation}', [OpeningBalanceController::class, 'update'])->name('opening-balance.update');
        Route::delete('/opening-balance/{operation}', [OpeningBalanceController::class, 'destroy'])->name('opening-balance.destroy');
        Route::post('/opening-balance/{operation}/post', [OpeningBalanceController::class, 'post'])->name('opening-balance.post');
    });

    Route::middleware('module.entitled:projects')->group(function (): void {
        Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    });
    Route::middleware('module.entitled:projects,project,projects')->group(function (): void {
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
        Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
        Route::patch('/projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
        Route::patch('/projects/{project}/restore', [ProjectController::class, 'restore'])->name('projects.restore');
        Route::patch('/projects/{project}/activate', [ProjectController::class, 'activate'])->name('projects.activate');
        Route::patch('/projects/{project}/revert-to-draft', [ProjectController::class, 'revertToDraft'])->name('projects.revert-to-draft');
        Route::patch('/projects/{project}/complete', [ProjectController::class, 'complete'])->name('projects.complete');
        Route::patch('/projects/{project}/cancel', [ProjectController::class, 'cancel'])->name('projects.cancel');
    });

    Route::middleware('module.entitled:reservations')->group(function (): void {
        Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations.index');
        Route::get('/reservations/available-units', [ReservationController::class, 'availableUnits'])->name('reservations.available-units');
        Route::post('/reservations', [ReservationController::class, 'store'])->name('reservations.store');
    });
    Route::middleware('module.entitled:reservations,reservation,reservations')->group(function (): void {
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
        Route::patch('/reservations/{reservation}', [ReservationController::class, 'update'])->name('reservations.update');
        Route::patch('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel');
    });

    Route::middleware('module.entitled:contracts')->group(function (): void {
        Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::post('/contracts', [ContractController::class, 'store'])->name('contracts.store');
    });
    Route::middleware('module.entitled:contracts,contract,contracts')->group(function (): void {
        Route::get('/contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
        Route::patch('/contracts/{contract}', [ContractController::class, 'update'])->name('contracts.update');
        Route::patch('/contracts/{contract}/activate', [ContractController::class, 'activate'])->name('contracts.activate');
        Route::patch('/contracts/{contract}/complete', [ContractController::class, 'complete'])->name('contracts.complete');
        Route::patch('/contracts/{contract}/cancel', [ContractController::class, 'cancel'])->name('contracts.cancel');
    });

    Route::get('/collections', CollectionsIndexController::class)
        ->middleware('module.entitled:collections')
        ->name('collections.index');

    Route::middleware('module.entitled:crm')->group(function (): void {
        Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
        Route::post('/leads', [LeadController::class, 'store'])->name('leads.store');
    });
    Route::middleware('module.entitled:crm,lead,leads')->group(function (): void {
        Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
        Route::patch('/leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
        Route::patch('/leads/{lead}/stage', [LeadController::class, 'moveStage'])->name('leads.stage');
        Route::patch('/leads/{lead}/claim', [LeadController::class, 'claim'])->name('leads.claim');
        Route::patch('/leads/{lead}/assign', [LeadController::class, 'assign'])->name('leads.assign');
        Route::patch('/leads/{lead}/lose', [LeadController::class, 'lose'])->name('leads.lose');
        Route::patch('/leads/{lead}/reopen', [LeadController::class, 'reopen'])->name('leads.reopen');
        Route::patch('/leads/{lead}/archive', [LeadController::class, 'archive'])->name('leads.archive');
        Route::patch('/leads/{lead}/restore', [LeadController::class, 'restore'])->name('leads.restore');
        Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');
        Route::get('/leads/{lead}/activities', [LeadActivityController::class, 'index'])->name('leads.activities.index');
        Route::post('/leads/{lead}/notes', [LeadActivityController::class, 'store'])->name('leads.notes.store');
        Route::post('/leads/{lead}/follow-up', [LeadFollowUpController::class, 'schedule'])->name('leads.follow-up.schedule');
        Route::patch('/leads/{lead}/follow-up', [LeadFollowUpController::class, 'reschedule'])->name('leads.follow-up.reschedule');
        Route::post('/leads/{lead}/follow-up/complete', [LeadFollowUpController::class, 'complete'])->name('leads.follow-up.complete');
        Route::post('/leads/{lead}/follow-up/cancel', [LeadFollowUpController::class, 'cancel'])->name('leads.follow-up.cancel');
    });

    Route::get('/contracts/{contract}/collection-schedule', [CollectionScheduleController::class, 'show'])
        ->middleware(['collections.contract', 'module.entitled:collections'])->name('contracts.collection-schedule.show');
    Route::post('/contracts/{contract}/collection-schedule/draft', [CollectionScheduleController::class, 'saveDraft'])
        ->middleware(['collections.contract', 'module.entitled:collections', 'collections.authorize:save_draft'])
        ->name('contracts.collection-schedule.draft');
    Route::post('/contracts/{contract}/collection-schedule/finalize', [CollectionScheduleController::class, 'finalize'])
        ->middleware(['collections.contract', 'module.entitled:collections', 'collections.authorize:finalize'])
        ->name('contracts.collection-schedule.finalize');
    Route::post('/contracts/{contract}/collection-schedule/amend', [CollectionScheduleController::class, 'amend'])
        ->middleware(['collections.contract', 'module.entitled:collections', 'collections.authorize:amend'])
        ->name('contracts.collection-schedule.amend');

    Route::middleware('module.entitled:units')->group(function (): void {
        Route::post('/units', [UnitController::class, 'store'])->name('units.store');
        Route::get('/units', [UnitController::class, 'index'])->name('units.index');
    });
    Route::middleware('module.entitled:units,unit,units')->group(function (): void {
        Route::get('/units/{unit}', [UnitController::class, 'show'])->name('units.show');
        Route::patch('/units/{unit}', [UnitController::class, 'update'])->name('units.update');
        Route::patch('/units/{unit}/archive', [UnitController::class, 'archive'])->name('units.archive');
        Route::patch('/units/{unit}/restore', [UnitController::class, 'restore'])->name('units.restore');
    });

    Route::apiResource('users', TenantUserController::class);

    Route::middleware('module.entitled:customers')->group(function (): void {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    });
    Route::middleware('module.entitled:customers,customer,customers')->group(function (): void {
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::patch('/customers/{customer}/archive', [CustomerController::class, 'archive'])->name('customers.archive');
        Route::patch('/customers/{customer}/restore', [CustomerController::class, 'restore'])->name('customers.restore');
    });
});
