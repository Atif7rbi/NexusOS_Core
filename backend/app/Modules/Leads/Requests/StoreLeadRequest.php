<?php

declare(strict_types=1);

namespace App\Modules\Leads\Requests;

use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Shared\Authorization\TenantAdministratorAuthority;
use App\Modules\Shared\Phone\Rules\SaudiMobileRule;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use Closure;
use Illuminate\Validation\Rule;

final class StoreLeadRequest extends LeadRequest
{
    protected function prepareForValidation(): void
    {
        $this->rejectUnknownKeys([
            'name',
            'phone',
            'email',
            'source',
            'source_detail',
            'project_id',
            'unit_id',
            'assigned_to',
            'next_follow_up_at',
            'acknowledge_duplicate',
        ]);
        $this->normalizeRequiredPhone();
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        $administrator = $this->user() !== null
            && TenantAdministratorAuthority::allows($this->user());

        return [
            'name' => ['required', 'string', $this->notBlank(), 'max:255'],
            'phone' => [
                'required',
                'string',
                new SaudiMobileRule(
                    app(SaudiMobileNormalizer::class),
                    required: true,
                ),
            ],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'source' => ['required', Rule::enum(LeadSource::class)],
            'source_detail' => [
                'required_if:source,other',
                'prohibited_unless:source,other',
                'nullable',
                'string',
                $this->notBlankWhenPresent(),
            ],
            'project_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'unit_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'assigned_to' => $administrator
                ? ['sometimes', 'nullable', 'integer', 'min:1']
                : ['prohibited'],
            'next_follow_up_at' => ['sometimes', 'nullable', 'date'],
            'acknowledge_duplicate' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم العميل المحتمل مطلوب.',
            'name.max' => 'اسم العميل المحتمل يجب ألا يتجاوز 255 حرفًا.',
            'phone.required' => 'رقم الجوال مطلوب.',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'source.required' => 'مصدر العميل المحتمل مطلوب.',
            'source.enum' => 'مصدر العميل المحتمل غير صحيح.',
            'source_detail.required_if' => 'تفاصيل المصدر مطلوبة عند اختيار مصدر آخر.',
            'source_detail.prohibited_unless' => 'تفاصيل المصدر مسموحة فقط عند اختيار مصدر آخر.',
            'assigned_to.prohibited' => 'لا يمكن لمستخدم المبيعات تحديد الإسناد عند إنشاء العميل المحتمل.',
            'assigned_to.integer' => 'المستخدم المسند إليه غير صحيح.',
            'next_follow_up_at.date' => 'موعد المتابعة غير صحيح.',
        ];
    }

    private function notBlank(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (trim((string) $value) === '') {
                $fail('يجب ألا تكون القيمة فارغة.');
            }
        };
    }

    private function notBlankWhenPresent(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && trim((string) $value) === '') {
                $fail('يجب ألا تكون القيمة فارغة.');
            }
        };
    }
}
