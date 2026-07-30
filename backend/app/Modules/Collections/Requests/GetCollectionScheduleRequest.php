<?php

declare(strict_types=1);

namespace App\Modules\Collections\Requests;

final class GetCollectionScheduleRequest extends CollectionScheduleRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys($this->query(), ['include_history']);

        if (! $this->query->has('include_history')) {
            return;
        }

        if (! in_array($this->query('include_history'), ['true', 'false', '1', '0', 1, 0, true, false], true)) {
            $this->fail('invalid_query_parameter', 'The include_history query parameter must be boolean.');
        }
    }

    public function rules(): array
    {
        return [];
    }

    public function includeHistory(): bool
    {
        return in_array($this->query('include_history'), ['true', '1', 1, true], true);
    }
}
