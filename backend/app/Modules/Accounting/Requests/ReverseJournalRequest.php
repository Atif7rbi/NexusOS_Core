<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

final class ReverseJournalRequest extends AccountingRequest
{
    public function rules(): array
    {
        return ['entry_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:500'], 'lines' => ['prohibited'], 'partial' => ['prohibited']];
    }
}
