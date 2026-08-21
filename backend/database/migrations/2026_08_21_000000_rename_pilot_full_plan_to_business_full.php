<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

return new class extends Migration
{
    private const LEGACY_KEY = 'pilot_full';

    private const CORE_KEY = 'business_full';

    public function up(): void
    {
        DB::transaction(function (): void {
            $legacyPlan = DB::table('plans')
                ->where('key', self::LEGACY_KEY)
                ->lockForUpdate()
                ->first();

            if ($legacyPlan === null) {
                return;
            }

            $corePlanExists = DB::table('plans')
                ->where('key', self::CORE_KEY)
                ->lockForUpdate()
                ->exists();

            if ($corePlanExists) {
                throw new RuntimeException(
                    'Cannot rename the legacy pilot_full plan because business_full already exists. Resolve the duplicate commercial Plan explicitly before retrying.',
                );
            }

            DB::table('plans')
                ->where('id', $legacyPlan->id)
                ->update([
                    'key' => self::CORE_KEY,
                    'name_ar' => 'الباقة التجارية الكاملة',
                    'name_en' => 'Business Full',
                    'updated_at' => now(),
                ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $corePlan = DB::table('plans')
                ->where('key', self::CORE_KEY)
                ->lockForUpdate()
                ->first();

            if ($corePlan === null) {
                return;
            }

            $legacyPlanExists = DB::table('plans')
                ->where('key', self::LEGACY_KEY)
                ->lockForUpdate()
                ->exists();

            if ($legacyPlanExists) {
                throw new RuntimeException(
                    'Cannot restore pilot_full because a plan with that key already exists.',
                );
            }

            DB::table('plans')
                ->where('id', $corePlan->id)
                ->update([
                    'key' => self::LEGACY_KEY,
                    'name_ar' => 'الباقة التجريبية الكاملة',
                    'name_en' => 'Pilot Full',
                    'updated_at' => now(),
                ]);
        });
    }
};
