<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreContractRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'reservation_id' => ['required', 'ulid', 'exists:reservations,id'],
            'total_amount' => ['required', 'decimal:0,2', 'gt:0'],
        ];
    }
}
