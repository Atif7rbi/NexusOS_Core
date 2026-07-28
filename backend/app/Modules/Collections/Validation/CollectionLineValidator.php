<?php

declare(strict_types=1);

namespace App\Modules\Collections\Validation;

use App\Modules\Collections\DTOs\CollectionLineData;
use App\Modules\Collections\Exceptions\BlankCollectionTitleException;
use App\Modules\Collections\Exceptions\ExcessDecimalPrecisionException;
use App\Modules\Collections\Exceptions\InvalidCollectionAmountException;
use App\Modules\Collections\Exceptions\InvalidCollectionSequenceException;

/**
 * Validates a single submitted Collection line against the invariants that
 * mirror the database CHECK constraints (DDL spec sections 13, 18, 19) and
 * the domain-enforced decimal-precision boundary (section 18, 31).
 *
 * This is a pre-persistence guard. The database CHECK constraints remain
 * the final authority once `feature/collections-ddl` is merged.
 */
final class CollectionLineValidator
{
    public function validate(CollectionLineData $line): void
    {
        if ($line->sequence <= 0) {
            throw new InvalidCollectionSequenceException();
        }

        if (trim($line->title) === '') {
            throw new BlankCollectionTitleException();
        }

        if (mb_strlen($line->title) > 150) {
            throw new BlankCollectionTitleException('Collection title must not exceed 150 characters.');
        }

        $this->assertPositiveAmount($line->amount);
        $this->assertAtMostTwoDecimalPlaces($line->amount);
    }

    private function assertPositiveAmount(string $amount): void
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidCollectionAmountException();
        }
    }

    private function assertAtMostTwoDecimalPlaces(string $amount): void
    {
        $normalized = ltrim($amount, '+-');

        if (! str_contains($normalized, '.')) {
            return;
        }

        [, $decimals] = explode('.', $normalized, 2);

        if (mb_strlen($decimals) > 2) {
            throw new ExcessDecimalPrecisionException();
        }
    }
}
