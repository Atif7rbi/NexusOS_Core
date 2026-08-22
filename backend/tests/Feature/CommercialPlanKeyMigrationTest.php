<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommercialPlanKeyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renames_the_legacy_commercial_plan_without_rewriting_its_identity(): void
    {
        $legacyPlan = Plan::query()->create([
            'key' => 'pilot_full',
            'name_ar' => 'الباقة التجريبية الكاملة',
            'name_en' => 'Pilot Full',
            'status' => Plan::STATUS_ACTIVE,
            'users_limit' => 5,
        ]);

        $migration = require database_path(
            'migrations/2026_08_21_000000_rename_pilot_full_plan_to_business_full.php',
        );

        $migration->up();

        $this->assertDatabaseMissing('plans', [
            'key' => 'pilot_full',
        ]);
        $this->assertDatabaseHas('plans', [
            'id' => $legacyPlan->id,
            'key' => 'business_full',
            'name_ar' => 'الباقة التجارية الكاملة',
            'name_en' => 'Business Full',
            'users_limit' => 5,
        ]);
    }
}
