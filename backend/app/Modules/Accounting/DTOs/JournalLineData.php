<?php

declare(strict_types=1);

namespace App\Modules\Accounting\DTOs;

use App\Modules\Accounting\Exceptions\AccountingValidationFailed;
use App\Modules\Accounting\ValueObjects\Money;

final readonly class JournalLineData
{
    public Money $debit;
    public Money $credit;

    public function __construct(public string $accountId, string|int $debit = '0', string|int $credit = '0', public ?string $memo = null)
    {
        $this->debit = Money::of($debit);
        $this->credit = Money::of($credit);
        if ($accountId === '' || $this->debit->isPositive() === $this->credit->isPositive()) {
            throw new AccountingValidationFailed('A journal line requires one positive debit or credit.');
        }
        if ($memo !== null && trim($memo) === '') {
            throw new AccountingValidationFailed('A journal memo cannot be blank.');
        }
    }
}
