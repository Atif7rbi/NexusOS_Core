<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Requests;

use App\Models\TenantUser;
use App\Modules\Shared\Time\TenantClock;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateReservationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $membership = $this->attributes->get('tenant_membership');

        if (
            ! $this->exists('expires_at')
            || ! is_string($this->input('expires_at'))
            || ! $membership instanceof TenantUser
        ) {
            return;
        }

        try {
            $this->merge([
                'expires_at' => app(TenantClock::class)
                    ->parse(
                        (string) $membership->tenant_id,
                        (string) $this->input('expires_at'),
                    )
                    ->toISOString(),
            ]);
        } catch (InvalidFormatException) {
            // Preserve invalid input for the validation rule.
        }
    }

    public function authorize(): bool { return $this->user() !== null; }

    public function rules(): array
    {
        return [
            'expires_at' => ['sometimes', 'date', 'after:now'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'unit_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'reserved_at' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
