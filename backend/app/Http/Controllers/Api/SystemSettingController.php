<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSystemSettingRequest;
use App\Models\SystemSetting;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use App\Modules\Shared\Services\ResolveActiveMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingController extends Controller
{
    public function __construct(
        private readonly SaudiMobileNormalizer $phoneNormalizer,
        private readonly ResolveActiveMembership $resolveActiveMembership,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $settings = SystemSetting::forTenant(
            (string) $this->resolveActiveMembership
                ->handle($request->user())
                ->tenant_id,
        );

        return response()->json([
            'data' => $settings,
        ]);
    }

    public function update(
        UpdateSystemSettingRequest $request,
    ): JsonResponse {
        if (config('nexusos.demo_mode')) {
            return response()->json([
                'message' => 'لا يمكن تعديل بيانات الشركة المشتركة في وضع العرض التجريبي.',
                'error' => [
                    'code' => 'company_profile_demo_read_only',
                ],
            ], 403);
        }

        $settings = SystemSetting::forTenant(
            (string) $this->resolveActiveMembership
                ->handle($request->user())
                ->tenant_id,
        );

        $validated = $request->validated();
        if (array_key_exists('phone', $validated)) $validated['phone'] = $this->phoneNormalizer->normalizeNullable($validated['phone']);
        $settings->update($validated);

        return response()->json([
            'message' => 'تم تحديث إعدادات النظام بنجاح.',
            'data' => $settings->fresh(),
        ]);
    }
}
