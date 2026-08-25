<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

final class StoreJournalRequest extends AccountingRequest
{
    public function rules(): array
    {
        return [
            'entry_date' => ['required', 'date_format:Y-m-d'], 'description' => ['required', 'string', 'max:500'],
            'status' => ['prohibited'], 'origin' => ['prohibited'], 'journal_number' => ['prohibited'],
            'accounting_period_id' => ['prohibited'], 'source_type' => ['prohibited'], 'source_id' => ['prohibited'],
            'posted_by' => ['prohibited'], 'posted_at' => ['prohibited'], 'reverses_journal_entry_id' => ['prohibited'],
        ] + $this->lineRules('sometimes');
    }
}
