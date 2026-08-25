<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http;

use App\Modules\Accounting\DTOs\JournalLineData;

final class AccountingLineMapper
{
    /** @param array<int,array<string,mixed>> $lines @return list<JournalLineData> */
    public function map(array $lines): array
    {
        return array_map(
            static fn (array $line): JournalLineData => new JournalLineData(
                $line['account_id'],
                $line['debit'],
                $line['credit'],
                $line['memo'] ?? null,
            ),
            array_values($lines),
        );
    }
}
