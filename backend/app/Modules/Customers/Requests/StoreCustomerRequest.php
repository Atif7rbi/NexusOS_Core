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

final class StoreCustomerRequest extends FormRequest
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

        $tenantId = $membership->tenant_id;

        return [
            'type' => [
                'required',
                Rule::enum(CustomerType::class),
            ],

            'category' => [
                'required',
                Rule::enum(CustomerCategory::class),
            ],

            'status' => [
                'sometimes',
                Rule::in(CustomerStatus::editableValues()),
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'phone' => [
                'required',
                'string',
                new SaudiMobileRule(app(SaudiMobileNormalizer::class), required: true),
                Rule::unique('customers', 'phone')
                    ->where(
                        fn ($query) => $query->where(
                            'tenant_id',
                            $tenantId
                        )
                    ),
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'national_id' => [
                'nullable',
                'string',
                'max:50',
                Rule::prohibitedIf(
                    fn (): bool => $this->input('type')
                        === CustomerType::Company->value
                ),
                Rule::unique('customers', 'national_id')
                    ->where(
                        fn ($query) => $query->where(
                            'tenant_id',
                            $tenantId
                        )
                    ),
            ],

            'commercial_registration_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::prohibitedIf(
                    fn (): bool => $this->input('type')
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
                ),
            ],

            'city' => [
                'nullable',
                'string',
                'max:120',
            ],

            'address' => [
                'nullable',
                'string',
                'max:500',
            ],

            'notes' => [
                'nullable',
                'string',
            ],
        ];
    }
    public function messages(): array { return ['phone.required' => 'رقم الجوال مطلوب.', 'phone.string' => 'صيغة رقم الجوال غير صحيحة. استخدم رقمًا محليًا من 10 أرقام يبدأ بـ 05.']; }
}
