<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

final class ClassificationCatalogRequest extends AccountingRequest
{
    public function rules(): array
    {
        return [];
    }

    protected function withValidator($validator): void
    {
        parent::withValidator($validator);
        $validator->after(function ($validator): void {
            foreach (array_keys($this->query()) as $key) {
                $validator->errors()->add($key, 'Classification catalog does not accept query filters.');
            }
        });
    }
}
