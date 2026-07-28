<?php

declare(strict_types=1);

namespace App\Modules\Collections\Validation;

use App\Modules\Collections\DTOs\CollectionLineData;
use App\Modules\Collections\Exceptions\DuplicateActiveSequenceException;
use App\Modules\Collections\Exceptions\NonDecreasingDueDateViolationException;
use App\Modules\Collections\Exceptions\ScheduleTotalMismatchException;
use App\Modules\Collections\Models\Collection;

/**
 * Validates invariants across a full set of active Collection rows
 * (DDL spec section 19, 22, 24, 25).
 *
 * Operates on plain {sequence, due_date, amount} rows so it can validate
 * either submitted (unsaved) lines before any write happens, or persisted
 * Collection rows after a mutation, using the same logic either way.
 */
final class CollectionScheduleValidator
{
    /**
     * @param  array<int, CollectionLineData>  $lines
     * @return array<int, array{sequence: int, due_date: string, amount: string}>
     */
    public function rowsFromLines(array $lines): array
    {
        return array_map(
            static fn (CollectionLineData $line): array => [
                'sequence' => $line->sequence,
                'due_date' => $line->dueDate->toDateString(),
                'amount' => $line->amount,
            ],
            $lines,
        );
    }

    /**
     * @param  array<int, Collection>  $collections
     * @return array<int, array{sequence: int, due_date: string, amount: string}>
     */
    public function rowsFromCollections(array $collections): array
    {
        return array_map(
            static fn (Collection $collection): array => [
                'sequence' => $collection->sequence,
                'due_date' => $collection->due_date->toDateString(),
                'amount' => (string) $collection->amount,
            ],
            $collections,
        );
    }

    /**
     * @param  array<int, array{sequence: int, due_date: string, amount: string}>  $rows
     */
    public function assertUniqueActiveSequences(array $rows): void
    {
        $seen = [];

        foreach ($rows as $row) {
            if (isset($seen[$row['sequence']])) {
                throw new DuplicateActiveSequenceException();
            }

            $seen[$row['sequence']] = true;
        }
    }

    /**
     * DDL spec section 22: sequence A < sequence B => A.due_date <= B.due_date.
     *
     * @param  array<int, array{sequence: int, due_date: string, amount: string}>  $rows
     */
    public function assertNonDecreasingDueDates(array $rows): void
    {
        $ordered = $rows;

        usort($ordered, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        $previousDueDate = null;

        foreach ($ordered as $row) {
            if ($previousDueDate !== null && $row['due_date'] < $previousDueDate) {
                throw new NonDecreasingDueDateViolationException();
            }

            $previousDueDate = $row['due_date'];
        }
    }

    /**
     * DDL spec section 24, 25: SUM(active Collection amounts) === Contract
     * total_amount, compared with exact decimal precision.
     *
     * @param  array<int, array{sequence: int, due_date: string, amount: string}>  $rows
     */
    public function assertTotalMatches(array $rows, string $contractTotalAmount): void
    {
        $sum = '0.00';

        foreach ($rows as $row) {
            $sum = bcadd($sum, $row['amount'], 2);
        }

        if (bccomp($sum, $contractTotalAmount, 2) !== 0) {
            throw new ScheduleTotalMismatchException();
        }
    }
}
