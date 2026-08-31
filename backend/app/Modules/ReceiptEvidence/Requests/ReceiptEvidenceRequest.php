<?php

declare(strict_types=1);

namespace App\Modules\ReceiptEvidence\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ReceiptEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->exists('tenant_id')) {
                $validator->errors()->add('tenant_id', 'The tenant_id field is prohibited.');
            }
        });
    }
}
