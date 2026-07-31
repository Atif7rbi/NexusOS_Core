<?php

declare(strict_types=1);

namespace App\Modules\Collections\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Collections\Requests\IndexCollectionsRequest;
use App\Modules\Collections\Support\CollectionsIndexQuery;
use App\Modules\Shared\Services\ResolveActiveMembership;
use Illuminate\Http\JsonResponse;

final class CollectionsIndexController extends Controller
{
    public function __construct(
        private readonly ResolveActiveMembership $resolveActiveMembership,
        private readonly CollectionsIndexQuery $indexQuery,
    ) {}

    public function __invoke(IndexCollectionsRequest $request): JsonResponse
    {
        $membership = $this->resolveActiveMembership->handle($request->user());

        return response()->json([
            'data' => $this->indexQuery->execute(
                (string) $membership->tenant_id,
                $request->validated(),
            ),
        ]);
    }
}
