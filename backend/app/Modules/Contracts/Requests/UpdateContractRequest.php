<?php

declare(strict_types=1);

namespace App\Modules\Contracts\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateContractRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'total_amount' => ['required', 'decimal:0,2', 'gt:0'],
            'tenant_id' => ['prohibited'],
            'reservation_id' => ['prohibited'],
            'status' => ['prohibited'],
            'activated_at' => ['prohibited'],
            'completed_at' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'created_by' => ['prohibited'],
        ];
    }
}
