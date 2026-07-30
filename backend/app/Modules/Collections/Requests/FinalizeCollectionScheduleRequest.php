<?php

declare(strict_types=1);

namespace App\Modules\Collections\Requests;

final class FinalizeCollectionScheduleRequest extends CollectionScheduleRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->all() !== []) {
            $this->fail('unexpected_request_fields', 'Finalize does not accept request fields.');
        }
    }

    public function rules(): array
    {
        return [];
    }
}
