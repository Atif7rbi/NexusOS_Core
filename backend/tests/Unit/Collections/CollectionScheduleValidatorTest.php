<?php

namespace Tests\Unit\Collections;

use App\Modules\Collections\DTOs\CollectionLineData;
use App\Modules\Collections\Exceptions\DuplicateActiveSequenceException;
use App\Modules\Collections\Exceptions\NonDecreasingDueDateViolationException;
use App\Modules\Collections\Exceptions\ScheduleTotalMismatchException;
use App\Modules\Collections\Validation\CollectionScheduleValidator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CollectionScheduleValidatorTest extends TestCase
{
    private CollectionScheduleValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new CollectionScheduleValidator();
    }

    public function test_unique_sequences_pass(): void
    {
        $rows = $this->validator->rowsFromLines([
            $this->line(1, '2026-01-01', '100.00'),
            $this->line(2, '2026-02-01', '100.00'),
        ]);

        $this->validator->assertUniqueActiveSequences($rows);

        $this->addToAssertionCount(1);
    }

    public function test_duplicate_sequence_is_rejected(): void
    {
        $this->expectException(DuplicateActiveSequenceException::class);

        $rows = $this->validator->rowsFromLines([
            $this->line(1, '2026-01-01', '100.00'),
            $this->line(1, '2026-02-01', '100.00'),
        ]);

        $this->validator->assertUniqueActiveSequences($rows);
    }

    public function test_non_decreasing_due_dates_pass_regardless_of_input_order(): void
    {
        $rows = $this->validator->rowsFromLines([
            $this->line(2, '2026-02-01', '100.00'),
            $this->line(1, '2026-01-01', '100.00'),
        ]);

        $this->validator->assertNonDecreasingDueDates($rows);

        $this->addToAssertionCount(1);
    }

    public function test_equal_due_dates_across_sequence_are_allowed(): void
    {
        $rows = $this->validator->rowsFromLines([
            $this->line(1, '2026-01-01', '100.00'),
            $this->line(2, '2026-01-01', '100.00'),
        ]);

        $this->validator->assertNonDecreasingDueDates($rows);

        $this->addToAssertionCount(1);
    }

    public function test_decreasing_due_date_by_sequence_is_rejected(): void
    {
        $this->expectException(NonDecreasingDueDateViolationException::class);

        $rows = $this->validator->rowsFromLines([
            $this->line(1, '2026-02-01', '100.00'),
            $this->line(2, '2026-01-01', '100.00'),
        ]);

        $this->validator->assertNonDecreasingDueDates($rows);
    }

    public function test_matching_total_passes(): void
    {
        $rows = $this->validator->rowsFromLines([
            $this->line(1, '2026-01-01', '600.00'),
            $this->line(2, '2026-02-01', '400.00'),
        ]);

        $this->validator->assertTotalMatches($rows, '1000.00');

        $this->addToAssertionCount(1);
    }

    public function test_mismatched_total_is_rejected(): void
    {
        $this->expectException(ScheduleTotalMismatchException::class);

        $rows = $this->validator->rowsFromLines([
            $this->line(1, '2026-01-01', '600.00'),
            $this->line(2, '2026-02-01', '399.99'),
        ]);

        $this->validator->assertTotalMatches($rows, '1000.00');
    }

    public function test_total_comparison_is_precise_to_the_cent(): void
    {
        // 333.33 + 333.33 + 333.34 = 1000.00 exactly.
        $rows = $this->validator->rowsFromLines([
            $this->line(1, '2026-01-01', '333.33'),
            $this->line(2, '2026-01-01', '333.33'),
            $this->line(3, '2026-01-01', '333.34'),
        ]);

        $this->validator->assertTotalMatches($rows, '1000.00');

        $this->addToAssertionCount(1);
    }

    private function line(int $sequence, string $dueDate, string $amount): CollectionLineData
    {
        return new CollectionLineData(
            id: null,
            sequence: $sequence,
            title: 'Installment '.$sequence,
            amount: $amount,
            dueDate: CarbonImmutable::parse($dueDate),
        );
    }
}
