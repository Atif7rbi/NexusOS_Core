<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Shared\Services\BusinessNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BusinessNumberTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_caller_owned_allocation_requires_and_participates_in_transaction(): void
    {
        $tenant = Tenant::factory()->create();
        $generator = new BusinessNumberGenerator();

        try {
            DB::transaction(function () use ($generator, $tenant): void {
                $allocated = $generator->generateWithinCurrentTransaction((string) $tenant->id, 'JRN', 2026);
                $this->assertSame('JRN-2026-001', $allocated['number']);
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertFalse(DB::table('business_number_sequences')
            ->where('tenant_id', $tenant->id)->where('prefix', 'JRN')->exists());
    }

    public function test_legacy_wrapper_preserves_project_number_format(): void
    {
        $tenant = Tenant::factory()->create();
        $allocated = (new BusinessNumberGenerator())->generate((string) $tenant->id, 'PRJ', 2026);

        $this->assertSame(['number'=>'PRJ-2026-001','year'=>2026,'sequence'=>1], $allocated);
    }
}
