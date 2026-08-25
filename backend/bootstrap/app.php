<?php

use App\Http\Middleware\EnsureActiveTenantMembership;
use App\Http\Middleware\EnsureModuleEntitled;
use App\Modules\Collections\Http\Middleware\AuthorizeCollectionScheduleCommand;
use App\Modules\Collections\Http\Middleware\ResolveCollectionScheduleContract;
use App\Modules\Collections\Support\CollectionScheduleExceptionResponder;
use App\Modules\Accounting\Exceptions\AccountingException;
use App\Modules\Accounting\Http\AccountingExceptionResponder;
use App\Modules\Leads\Exceptions\UserHasOpenAssignedLeadsException;
use App\Modules\Leads\Support\LeadExceptionResponder;
use App\Modules\Shared\Support\ApiErrorResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->trimStrings(except: ['phone']);
        $middleware->alias([
            'tenant.active' => EnsureActiveTenantMembership::class,
            'module.entitled' => EnsureModuleEntitled::class,
            'collections.contract' => ResolveCollectionScheduleContract::class,
            'collections.authorize' => AuthorizeCollectionScheduleCommand::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AccountingException $exception, Request $request) {
            if (! $request->is('api/accounting*')) {
                return null;
            }

            return app(AccountingExceptionResponder::class)->respond($exception);
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return app(ApiErrorResponse::class)->make(
                401,
                'unauthenticated',
                'Unauthenticated.',
            );
        });

        $exceptions->render(function (QueryException $exception, Request $request) {
            if (! $request->is('api/contracts/*/collection-schedule*')) {
                return null;
            }

            return app(CollectionScheduleExceptionResponder::class)->from($exception);
        });

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (
                ! $request->is('api/leads*')
                && ! ($exception instanceof UserHasOpenAssignedLeadsException)
            ) {
                return null;
            }

            return app(LeadExceptionResponder::class)->from($exception);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            [$code, $message] = match ($exception->getStatusCode()) {
                401 => ['unauthenticated', 'Unauthenticated.'],
                403 => ['forbidden', 'ليس لديك صلاحية لتنفيذ هذا الإجراء.'],
                404 => ['resource_not_found', 'المورد المطلوب غير متاح.'],
                409 => ['state_conflict', 'لا يمكن تنفيذ العملية بسبب تعارض في الحالة الحالية.'],
                default => [null, null],
            };

            if ($code === null || $message === null) {
                return null;
            }

            return app(ApiErrorResponse::class)->make(
                $exception->getStatusCode(),
                $code,
                $message,
            );
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status);
        });

        $exceptions->render(function (\Throwable $exception, Request $request) {
            if (
                ! $request->is('api/*')
                || $exception instanceof HttpExceptionInterface
                || $exception instanceof HttpResponseException
                || $exception instanceof ValidationException
            ) {
                return null;
            }

            return app(ApiErrorResponse::class)->make(
                500,
                'internal_error',
                'تعذر إكمال العملية.',
            );
        });
    })->create();
