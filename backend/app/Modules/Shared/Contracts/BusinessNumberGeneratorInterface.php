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
}
