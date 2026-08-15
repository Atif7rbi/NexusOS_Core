<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Http\JsonResponse;

final class ApiErrorResponse
{
    /**
     * @param array<string, mixed> $errorContext
     * @param array<string, mixed> $responseContext
     */
    public function make(
        int $status,
        string $code,
        string $message,
        array $errorContext = [],
        array $responseContext = [],
    ): JsonResponse {
        return response()->json([
            ...$responseContext,
            'message' => $message,
            'error' => [
                ...$errorContext,
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
