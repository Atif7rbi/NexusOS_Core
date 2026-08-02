<?php

namespace App\Modules\Users\Requests;

use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Shared\Phone\InvalidSaudiMobileNumberException;
use App\Modules\Shared\Phone\Rules\SaudiMobileRule;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateTenantUserRequest extends FormRequest
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
        $userId = $this->route('user')?->user_id;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'sometimes',
                'required',
                'email',
                'max:254',
                Rule::unique('users', 'email')
                    ->ignore($userId),
            ],

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                new SaudiMobileRule(app(SaudiMobileNormalizer::class), required: false),
            ],

            'role' => [
                'sometimes',
                'required',
                Rule::in([
                    User::ROLE_ADMINISTRATOR,
                    User::ROLE_PROJECT_MANAGER,
                    User::ROLE_SALES,
                    User::ROLE_ACCOUNTANT,
                    User::ROLE_EMPLOYEE,
                ]),
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in([
                    TenantUser::STATUS_ACTIVE,
                    TenantUser::STATUS_PAUSED,
                    TenantUser::STATUS_SUSPENDED,
                ]),
            ],

            'password' => [
                'sometimes',
                'nullable',
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
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'email.unique' => 'البريد الإلكتروني مستخدم مسبقًا.',
            'phone.string' => 'صيغة رقم الجوال غير صحيحة. استخدم رقمًا محليًا من 10 أرقام يبدأ بـ 05.',
            'role.in' => 'الدور الوظيفي المحدد غير صحيح.',
            'status.in' => 'حالة المستخدم المحددة غير صحيحة.',
            'password.confirmed' => 'تأكيد كلمة المرور غير مطابق.',
        ];
    }
}
