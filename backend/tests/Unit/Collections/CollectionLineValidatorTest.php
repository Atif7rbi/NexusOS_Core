<?php

namespace Tests\Unit\Collections;

use App\Modules\Collections\DTOs\CollectionLineData;
use App\Modules\Collections\Exceptions\BlankCollectionTitleException;
use App\Modules\Collections\Exceptions\ExcessDecimalPrecisionException;
use App\Modules\Collections\Exceptions\InvalidCollectionAmountException;
use App\Modules\Collections\Exceptions\InvalidCollectionSequenceException;
use App\Modules\Collections\Validation\CollectionLineValidator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CollectionLineValidatorTest extends TestCase
{
    private CollectionLineValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new CollectionLineValidator();
    }

    public function test_valid_line_passes(): void
    {
        $line = $this->makeLine(sequence: 1, title: 'Down payment', amount: '1000.00');

        $this->validator->validate($line);

        $this->addToAssertionCount(1);
    }

    public function test_zero_sequence_is_rejected(): void
    {
        $this->expectException(InvalidCollectionSequenceException::class);

        $this->validator->validate($this->makeLine(sequence: 0));
    }

    public function test_negative_sequence_is_rejected(): void
    {
        $this->expectException(InvalidCollectionSequenceException::class);

        $this->validator->validate($this->makeLine(sequence: -1));
    }

    public function test_blank_title_is_rejected(): void
    {
        $this->expectException(BlankCollectionTitleException::class);

        $this->validator->validate($this->makeLine(title: '   '));
    }

    public function test_title_over_150_characters_is_rejected(): void
    {
        $this->expectException(BlankCollectionTitleException::class);

        $this->validator->validate($this->makeLine(title: str_repeat('a', 151)));
    }

    public function test_zero_amount_is_rejected(): void
    {
        $this->expectException(InvalidCollectionAmountException::class);

        $this->validator->validate($this->makeLine(amount: '0.00'));
    }

    public function test_negative_amount_is_rejected(): void
    {
        $this->expectException(InvalidCollectionAmountException::class);

        $this->validator->validate($this->makeLine(amount: '-10.00'));
    }

    public function test_amount_with_more_than_two_decimals_is_rejected(): void
    {
        $this->expectException(ExcessDecimalPrecisionException::class);

        $this->validator->validate($this->makeLine(amount: '10.123'));
    }

    public function test_amount_with_exactly_two_decimals_is_accepted(): void
    {
        $this->validator->validate($this->makeLine(amount: '10.12'));

        $this->addToAssertionCount(1);
    }

    public function test_integer_amount_without_decimals_is_accepted(): void
    {
        $this->validator->validate($this->makeLine(amount: '500'));

        $this->addToAssertionCount(1);
    }

    private function makeLine(
        int $sequence = 1,
        string $title = 'Installment',
        string $amount = '100.00',
    ): CollectionLineData {
        return new CollectionLineData(
            id: null,
            sequence: $sequence,
            title: $title,
            amount: $amount,
            dueDate: CarbonImmutable::parse('2026-01-01'),
        );
    }
}
