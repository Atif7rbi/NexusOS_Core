<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\ValueObjects\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[DataProvider('exactValues')]
    public function test_it_normalizes_exact_numeric_19_2_values(string|int $input, string $expected): void
    {
        self::assertSame($expected, (string) Money::of($input));
    }

    public static function exactValues(): array
    {
        return [['10', '10.00'], ['10.1', '10.10'], ['0', '0.00'], ['-1.25', '-1.25'], [10, '10.00']];
    }

    #[DataProvider('invalidValues')]
    public function test_it_rejects_rounding_and_overflow(string $input): void
    {
        $this->expectException(AccountingValidationFailed::class);
        Money::of($input);
    }

    public static function invalidValues(): array
    {
        return [['10.001'], ['999999999999999999.99'], ['NaN']];
    }

    public function test_exact_addition_never_uses_binary_float(): void
    {
        self::assertSame('0.30', (string) Money::of('0.10')->plus(Money::of('0.20')));
    }
}
