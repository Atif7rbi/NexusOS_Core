<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

final class WriteOpeningBalanceRequest extends AccountingRequest
{
    public function rules(): array
    {
        return [
            'accounting_date' => ['required', 'date_format:Y-m-d'], 'status' => ['prohibited'],
            'effect_state' => ['prohibited'], 'journal_entry_id' => ['prohibited'],
            'latest_effect_journal_entry_id' => ['prohibited'], 'posted_by' => ['prohibited'],
            'posted_at' => ['prohibited'],
        ] + $this->lineRules();
    }
}
