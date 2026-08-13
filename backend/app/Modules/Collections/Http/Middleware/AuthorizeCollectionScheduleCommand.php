<?php

declare(strict_types=1);

namespace App\Modules\Collections\Http\Middleware;

use App\Models\User;
use App\Modules\Collections\Http\Exceptions\RoleNotAuthorizedException;
use App\Modules\Collections\Support\CollectionScheduleAuthorization;
use App\Modules\Collections\Support\CollectionScheduleExceptionResponder;
use App\Modules\Contracts\Models\Contract;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeCollectionScheduleCommand
{
    public function __construct(
        private readonly CollectionScheduleAuthorization $authorization,
        private readonly CollectionScheduleExceptionResponder $exceptions,
    ) {}

    public function handle(Request $request, Closure $next, string $command): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new \LogicException('Authenticated user was not resolved.');
        }

        $contract = $request->attributes->get('collection_schedule_contract');

        if (! $contract instanceof Contract) {
            throw new \LogicException('Collection Schedule Contract was not resolved.');
        }

        try {
            switch ($command) {
                case 'save_draft':
                    $this->authorization->assertCanSaveDraft($user, $contract);
                    break;

                case 'finalize':
                    $this->authorization->assertCanFinalize($user);
                    break;

                case 'amend':
                    $this->authorization->assertCanAmend($user);
                    break;

                default:
                    throw new \LogicException('Unknown Collection Schedule command.');
            }
        } catch (RoleNotAuthorizedException $exception) {
            $response = $this->exceptions->from($exception);

            if ($response === null) {
                throw $exception;
            }

            return $response;
        }

        return $next($request);
    }
}
