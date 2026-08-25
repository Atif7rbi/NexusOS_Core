<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Support\AccountingAuthorization;
use App\Modules\Shared\Services\ResolveActiveMembership;
use Illuminate\Http\Request;

abstract class AccountingController extends Controller
{
    public function __construct(
        private readonly ResolveActiveMembership $memberships,
        private readonly AccountingAuthorization $authorization,
    ) {}

    /** @return array{string,User} */
    protected function context(Request $request, string $capability): array
    {
        /** @var User $actor */
        $actor = $request->user();
        $membership = $this->memberships->handle($actor);
        $tenantId = (string) $membership->tenant_id;
        $this->authorization->authorize($tenantId, $actor, $capability);

        return [$tenantId, $actor];
    }
}
