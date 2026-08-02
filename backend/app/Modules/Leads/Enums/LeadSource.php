<?php

declare(strict_types=1);

namespace App\Modules\Leads\Enums;

enum LeadSource: string
{
    case WhatsApp = 'whatsapp';
    case PhoneCall = 'phone_call';
    case Website = 'website';
    case SocialMedia = 'social_media';
    case Referral = 'referral';
    case WalkIn = 'walk_in';
    case Advertising = 'advertising';
    case Exhibition = 'exhibition';
    case Other = 'other';
}
