<?php

namespace App\Modules\Shared\Contracts;

interface BusinessNumberGeneratorInterface
{
    /**
     * @return array{
     *     number: string,
     *     year: int,
     *     sequence: int
     * }
     */
    public function generate(
        string $tenantId,
        string $prefix,
        int $year,
    ): array;

    /**
     * Allocate inside the caller-owned database transaction.
     *
     * @return array{
     *     number: string,
     *     year: int,
     *     sequence: int
     * }
     */
    public function generateWithinCurrentTransaction(
        string $tenantId,
        string $prefix,
        int $year,
    ): array;
}
