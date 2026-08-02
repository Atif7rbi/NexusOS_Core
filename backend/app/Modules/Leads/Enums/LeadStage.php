<?php

declare(strict_types=1);

namespace App\Modules\Leads\Enums;

use LogicException;

enum LeadStage: string
{
    case New = 'new';
    case Qualified = 'qualified';
    case Viewing = 'viewing';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [
            self::New->value,
            self::Qualified->value,
            self::Viewing->value,
            self::Negotiation->value,
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::openValues(), true);
    }

    public function rank(): int
    {
        return match ($this) {
            self::New => 10,
            self::Qualified => 20,
            self::Viewing => 30,
            self::Negotiation => 40,
            self::Won, self::Lost => throw new LogicException(
                'Closed Lead stages do not have an open-stage rank.'
            ),
        };
    }
}
