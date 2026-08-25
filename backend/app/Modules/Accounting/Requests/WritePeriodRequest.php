<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

final class WritePeriodRequest extends AccountingRequest
{
    public function rules(): array
    {
        return ['start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'], 'status' => ['prohibited']];
    }
}
