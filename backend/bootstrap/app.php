<?php

use App\Http\Middleware\EnsureActiveTenantMembership;
use App\Modules\Collections\Http\Middleware\AuthorizeCollectionScheduleCommand;
use App\Modules\Collections\Http\Middleware\ResolveCollectionScheduleContract;
use App\Modules\Collections\Support\CollectionScheduleExceptionResponder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.active' => EnsureActiveTenantMembership::class,
            'collections.contract' => ResolveCollectionScheduleContract::class,
            'collections.authorize' => AuthorizeCollectionScheduleCommand::class,
        ]);

})
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/contracts/*/collection-schedule*')) {
                return null;
            }

            return response()->json([
                'message' => 'Unauthenticated.',
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Unauthenticated.',
                ],
            ], 401);
        });

        $exceptions->render(function (QueryException $exception, Request $request) {
            if (! $request->is('api/contracts/*/collection-schedule*')) {
                return null;
            }

            return app(CollectionScheduleExceptionResponder::class)->from($exception);
        });
    })->create();
