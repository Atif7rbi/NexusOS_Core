<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

final class ReopenPeriodRequest extends AccountingRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
