<?php

declare(strict_types=1);

namespace App\Modules\Accounting\DTOs;

use App\Modules\Accounting\Exceptions\AccountingValidationFailed;

final readonly class BusinessPostingRequest
{
    public function __construct(public string $tenantId,public int $actorId,public string $sourceType,public string $sourceId,public string $currency,public string $entryDate,public string $description,public array $lines)
    {
        if($currency!=='SAR'||!preg_match('/^[a-z][a-z0-9_]{0,63}$/',$sourceType)||$sourceId===''||trim($description)===''){throw new AccountingValidationFailed('Invalid business posting request.');}
        foreach($lines as $line){if(!$line instanceof JournalLineData){throw new AccountingValidationFailed('Invalid business posting line.');}}
    }
}
