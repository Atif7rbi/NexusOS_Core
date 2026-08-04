<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SystemSettingController;
use App\Modules\Collections\Controllers\CollectionScheduleController;
use App\Modules\Collections\Controllers\CollectionsIndexController;
use App\Modules\Contracts\Controllers\ContractController;
use App\Modules\Customers\Controllers\CustomerController;
use App\Modules\Leads\Controllers\LeadActivityController;
use App\Modules\Leads\Controllers\LeadController;
use App\Modules\Projects\Controllers\ProjectController;
use App\Modules\Reservations\Controllers\ReservationController;
use App\Modules\Units\Controllers\UnitController;
use App\Modules\Users\Controllers\TenantUserController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/system-settings',
    [SystemSettingController::class, 'show']
);

Route::post(
    '/auth/login',
    [AuthController::class, 'login']
)->middleware('throttle:5,1');

Route::middleware([
    'auth:sanctum',
    'tenant.active',
])->group(function (): void {

    Route::get(
        '/auth/user',
        [AuthController::class, 'user']
    );

    Route::post(
        '/auth/logout',
        [AuthController::class, 'logout']
    );

    Route::put(
        '/system-settings',
        [SystemSettingController::class, 'update']
    );

    Route::get('/projects', [ProjectController::class, 'index'])
        ->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])
        ->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])
        ->name('projects.show');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])
        ->name('projects.update');
    Route::patch('/projects/{project}/archive', [ProjectController::class, 'archive'])
        ->name('projects.archive');
    Route::patch('/projects/{project}/restore', [ProjectController::class, 'restore'])
        ->name('projects.restore');
    Route::patch('/projects/{project}/activate', [ProjectController::class, 'activate'])
        ->name('projects.activate');
    Route::patch('/projects/{project}/revert-to-draft', [ProjectController::class, 'revertToDraft'])
        ->name('projects.revert-to-draft');
    Route::patch('/projects/{project}/complete', [ProjectController::class, 'complete'])
        ->name('projects.complete');
    Route::patch('/projects/{project}/cancel', [ProjectController::class, 'cancel'])
        ->name('projects.cancel');

    Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations.index');
    Route::get('/reservations/available-units', [ReservationController::class, 'availableUnits'])->name('reservations.available-units');
    Route::post('/reservations', [ReservationController::class, 'store'])->name('reservations.store');
    Route::get('/reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
    Route::patch('/reservations/{reservation}', [ReservationController::class, 'update'])->name('reservations.update');
    Route::patch('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel');

    Route::get('/contracts', [ContractController::class, 'index'])->name('contracts.index');
    Route::post('/contracts', [ContractController::class, 'store'])->name('contracts.store');
    Route::get('/contracts/{contract}', [ContractController::class, 'show'])->name('contracts.show');
    Route::patch('/contracts/{contract}', [ContractController::class, 'update'])->name('contracts.update');
    Route::patch('/contracts/{contract}/activate', [ContractController::class, 'activate'])->name('contracts.activate');
    Route::patch('/contracts/{contract}/complete', [ContractController::class, 'complete'])->name('contracts.complete');
    Route::patch('/contracts/{contract}/cancel', [ContractController::class, 'cancel'])->name('contracts.cancel');

    Route::get('/collections', CollectionsIndexController::class)
        ->name('collections.index');

    Route::get('/leads', [LeadController::class, 'index'])
        ->name('leads.index');
    Route::post('/leads', [LeadController::class, 'store'])
        ->name('leads.store');
    Route::get('/leads/{lead}', [LeadController::class, 'show'])
        ->name('leads.show');
    Route::patch('/leads/{lead}', [LeadController::class, 'update'])
        ->name('leads.update');
    Route::patch('/leads/{lead}/stage', [LeadController::class, 'moveStage'])
        ->name('leads.stage');
    Route::patch('/leads/{lead}/claim', [LeadController::class, 'claim'])
        ->name('leads.claim');
    Route::patch('/leads/{lead}/assign', [LeadController::class, 'assign'])
        ->name('leads.assign');
    Route::patch('/leads/{lead}/lose', [LeadController::class, 'lose'])
        ->name('leads.lose');
    Route::patch('/leads/{lead}/reopen', [LeadController::class, 'reopen'])
        ->name('leads.reopen');
    Route::patch('/leads/{lead}/archive', [LeadController::class, 'archive'])
        ->name('leads.archive');
    Route::patch('/leads/{lead}/restore', [LeadController::class, 'restore'])
        ->name('leads.restore');
    Route::post('/leads/{lead}/convert', [LeadController::class, 'convert'])
        ->name('leads.convert');
    Route::get('/leads/{lead}/activities', [LeadActivityController::class, 'index'])
        ->name('leads.activities.index');
    Route::post('/leads/{lead}/notes', [LeadActivityController::class, 'store'])
        ->name('leads.notes.store');

    Route::get(
        '/contracts/{contract}/collection-schedule',
        [CollectionScheduleController::class, 'show'],
    )->middleware('collections.contract')
        ->name('contracts.collection-schedule.show');
    Route::post(
        '/contracts/{contract}/collection-schedule/draft',
        [CollectionScheduleController::class, 'saveDraft'],
    )->middleware([
        'collections.contract',
        'collections.authorize:save_draft',
    ])->name('contracts.collection-schedule.draft');
    Route::post(
        '/contracts/{contract}/collection-schedule/finalize',
        [CollectionScheduleController::class, 'finalize'],
    )->middleware([
        'collections.contract',
        'collections.authorize:finalize',
    ])->name('contracts.collection-schedule.finalize');
    Route::post(
        '/contracts/{contract}/collection-schedule/amend',
        [CollectionScheduleController::class, 'amend'],
    )->middleware([
        'collections.contract',
        'collections.authorize:amend',
    ])->name('contracts.collection-schedule.amend');

    Route::post(
        '/units',
        [UnitController::class, 'store']
    )->name('units.store');

    Route::get(
        '/units',
        [UnitController::class, 'index']
    )->name('units.index');

    Route::get(
        '/units/{unit}',
        [UnitController::class, 'show']
    )->name('units.show');

    Route::patch(
        '/units/{unit}',
        [UnitController::class, 'update']
    )->name('units.update');

    Route::patch(
        '/units/{unit}/archive',
        [UnitController::class, 'archive']
    )->name('units.archive');

    Route::patch(
        '/units/{unit}/restore',
        [UnitController::class, 'restore']
    )->name('units.restore');

    Route::apiResource(
        'users',
        TenantUserController::class
    );

    Route::get(
        '/customers',
        [CustomerController::class, 'index']
    )->name('customers.index');

    Route::post(
        '/customers',
        [CustomerController::class, 'store']
    )->name('customers.store');

    Route::get(
        '/customers/{customer}',
        [CustomerController::class, 'show']
    )->name('customers.show');

    Route::patch(
        '/customers/{customer}',
        [CustomerController::class, 'update']
    )->name('customers.update');

    Route::patch(
        '/customers/{customer}/archive',
        [CustomerController::class, 'archive']
    )->name('customers.archive');

    Route::patch(
        '/customers/{customer}/restore',
        [CustomerController::class, 'restore']
    )->name('customers.restore');
});
