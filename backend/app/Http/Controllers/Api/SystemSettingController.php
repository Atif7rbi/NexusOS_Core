<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSystemSettingRequest;
use App\Models\SystemSetting;
use App\Modules\Shared\Phone\SaudiMobileNormalizer;
use Illuminate\Http\JsonResponse;

class SystemSettingController extends Controller
{
    public function __construct(private readonly SaudiMobileNormalizer $phoneNormalizer) {}
    public function show(): JsonResponse
    {
        $settings = SystemSetting::query()->firstOrFail();

        return response()->json([
            'data' => $settings,
        ]);
    }

    public function update(
        UpdateSystemSettingRequest $request
    ): JsonResponse {
        $settings = SystemSetting::query()->firstOrFail();

        $validated = $request->validated();
        if (array_key_exists('phone', $validated)) $validated['phone'] = $this->phoneNormalizer->normalizeNullable($validated['phone']);
        $settings->update($validated);

        return response()->json([
            'message' => 'تم تحديث إعدادات النظام بنجاح.',
            'data' => $settings->fresh(),
        ]);
    }
}
