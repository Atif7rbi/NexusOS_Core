<?php

declare(strict_types=1);

namespace App\Modules\Payments\ValueObjects;

use App\Modules\Payments\Exceptions\PaymentsValidationFailed;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

final readonly class PaymentAmount
{
    private function __construct(private BigDecimal $amount) {}

    public static function of(string|int $value): self
    {
        try {
            $amount = BigDecimal::of($value)->toScale(2, RoundingMode::UNNECESSARY);
        } catch (MathException|\InvalidArgumentException $exception) {
            throw new PaymentsValidationFailed('amount must be an exact decimal with at most two fractional digits.', previous: $exception);
        }

        if (! $amount->isPositive() || $amount->isGreaterThan(BigDecimal::of('99999999999999999.99'))) {
            throw new PaymentsValidationFailed('amount must be greater than zero and fit NUMERIC(19,2).');
        }

        return new self($amount);
    }

    public function __toString(): string
    {
        return (string) $this->amount;
    }
}
