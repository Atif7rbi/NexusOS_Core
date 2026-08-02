<?php

namespace App\Modules\Users\Requests;

use App\Models\User;
use App\Modules\Shared\Phone\InvalidSaudiMobileNumberException;
use App\Modules\Shared\Phone\Rules\SaudiMobileRule;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreTenantUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->exists('phone')) return;
        try { $this->merge(['phone' => app(SaudiMobileNormalizer::class)->normalizeNullable($this->input('phone'))]); }
        catch (InvalidSaudiMobileNumberException) { /* Preserve invalid input for the rule. */ }
    }
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:254',
                Rule::unique('users', 'email'),
            ],

            'phone' => [
                'nullable',
                'string',
                new SaudiMobileRule(app(SaudiMobileNormalizer::class), required: false),
            ],

            'role' => [
                'required',
                Rule::in([
                    User::ROLE_ADMINISTRATOR,
                    User::ROLE_PROJECT_MANAGER,
                    User::ROLE_SALES,
                    User::ROLE_ACCOUNTANT,
                    User::ROLE_EMPLOYEE,
                ]),
            ],

            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم المستخدم مطلوب.',
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'email.unique' => 'البريد الإلكتروني مستخدم مسبقًا.',
            'phone.string' => 'صيغة رقم الجوال غير صحيحة. استخدم رقمًا محليًا من 10 أرقام يبدأ بـ 05.',
            'role.required' => 'الدور الوظيفي مطلوب.',
            'role.in' => 'الدور الوظيفي المحدد غير صحيح.',
            'password.required' => 'كلمة المرور المؤقتة مطلوبة.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
        ];
    }
}
