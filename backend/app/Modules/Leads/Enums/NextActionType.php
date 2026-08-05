<?php

declare(strict_types=1);

namespace App\Modules\Leads\Enums;

enum NextActionType: string
{
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Meeting = 'meeting';
    case SiteVisit = 'site_visit';
    case SendOffer = 'send_offer';
    case WaitingResponse = 'waiting_response';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        );
    }

    public function requiresNote(): bool
    {
        return $this === self::Other;
    }
}
