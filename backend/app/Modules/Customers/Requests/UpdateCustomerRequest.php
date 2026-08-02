<?php

declare(strict_types=1);

namespace App\Modules\Customers\Requests;

use App\Modules\Customers\Enums\CustomerCategory;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\CustomerType;
use App\Modules\Shared\Phone\InvalidSaudiMobileNumberException;
use App\Modules\Shared\Phone\Rules\SaudiMobileRule;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use App\Modules\Shared\Services\ResolveActiveMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->exists('phone')) return;
        try { $this->merge(['phone' => app(SaudiMobileNormalizer::class)->normalizeRequired($this->input('phone'))]); }
        catch (InvalidSaudiMobileNumberException) { /* Preserve invalid input for the rule. */ }
    }
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(
        ResolveActiveMembership $resolveActiveMembership,
    ): array {
        $membership = $resolveActiveMembership->handle(
            $this->user()
        );

        $tenantId = (string) $membership->tenant_id;
        $customerId = $this->customerId();

        return [
            'type' => [
                'sometimes',
                'required',
                Rule::enum(CustomerType::class),
            ],

            'category' => [
                'sometimes',
                'required',
                Rule::enum(CustomerCategory::class),
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in(CustomerStatus::editableValues()),
            ],

            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'phone' => [
                'sometimes',
                'required',
                'string',
                new SaudiMobileRule(app(SaudiMobileNormalizer::class), required: true),
                Rule::unique('customers', 'phone')
                    ->where(
                        fn ($query) => $query->where(
                            'tenant_id',
                            $tenantId
                        )
                    )
                    ->ignore($customerId),
            ],

            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
            ],

            'national_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::prohibitedIf(
                    fn (): bool => $this->submittedType()
                        === CustomerType::Company->value
                ),
                Rule::unique('customers', 'national_id')
                    ->where(
                        fn ($query) => $query->where(
                            'tenant_id',
                            $tenantId
                        )
                    )
                    ->ignore($customerId),
            ],

            'commercial_registration_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::prohibitedIf(
                    fn (): bool => $this->submittedType()
                        === CustomerType::Individual->value
                ),
                Rule::unique(
                    'customers',
                    'commercial_registration_number'
                )->where(
                    fn ($query) => $query->where(
                        'tenant_id',
                        $tenantId
                    )
                )->ignore($customerId),
            ],

            'city' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],

            'address' => [
                'sometimes',
                'nullable',
                'string',
                'max:500',
            ],

            'notes' => [
                'sometimes',
                'nullable',
                'string',
            ],
        ];
    }

    private function customerId(): string
    {
        return (string) $this->route('customer');
    }

    private function submittedType(): ?string
    {
        if (! $this->has('type')) {
            return null;
        }

        return $this->string('type')->toString();
    }
    public function messages(): array { return ['phone.required' => 'رقم الجوال مطلوب.', 'phone.string' => 'صيغة رقم الجوال غير صحيحة. استخدم رقمًا محليًا من 10 أرقام يبدأ بـ 05.']; }
}
