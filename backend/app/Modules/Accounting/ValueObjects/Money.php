<?php

declare(strict_types=1);

namespace App\Modules\Accounting\ValueObjects;

use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

final readonly class Money
{
    private function __construct(private BigDecimal $amount) {}

    public static function of(string|int $value): self
    {
        try {
            $amount = BigDecimal::of($value)->toScale(2, RoundingMode::UNNECESSARY);
        } catch (MathException|\InvalidArgumentException $exception) {
            throw new AccountingValidationFailed('Money must be an exact decimal with at most two fractional digits.', previous: $exception);
        }

        if ($amount->abs()->isGreaterThan(BigDecimal::of('99999999999999999.99'))) {
            throw new AccountingValidationFailed('Money exceeds NUMERIC(19,2).');
        }

        return new self($amount);
    }

    public static function zero(): self
    {
        return self::of('0');
    }

    public function plus(self $other): self
    {
        return new self($this->amount->plus($other->amount));
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function equals(self $other): bool
    {
        return $this->amount->isEqualTo($other->amount);
    }

    public function __toString(): string
    {
        return (string) $this->amount;
    }
}
