<?php

declare(strict_types=1);

namespace App\Modules\Leads\Enums;

enum ConversionMode: string
{
    /** A new Customer record was created from the Lead's data. */
    case Created = 'created';

    /** Lead was linked to an existing Customer (status unchanged). */
    case LinkedExisting = 'linked_existing';

    /** Lead was linked to an existing Customer whose status was promoted from lead → customer. */
    case LinkedAndPromoted = 'linked_and_promoted';
}
