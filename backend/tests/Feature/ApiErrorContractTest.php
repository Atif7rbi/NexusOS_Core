<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ApiErrorContractTest extends ApiTestCase
{
    public function test_unauthenticated_api_response_uses_the_canonical_contract(): void
    {
        $this->getJson('/api/projects')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Unauthenticated.',
                ],
            ]);
    }

    public function test_same_tenant_authorization_denial_uses_the_canonical_contract(): void
    {
        $sales = $this->createActiveUser([
            'role' => User::ROLE_SALES,
        ]);

        Sanctum::actingAs($sales);

        $this->postJson('/api/projects', $this->projectPayload())
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
                ],
            ]);
    }

    public function test_cross_tenant_resource_is_a_non_disclosing_canonical_not_found(): void
    {
        $actor = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);
        $otherTenant = Tenant::factory()->create();
        $otherAdministrator = User::factory()->create([
            'role' => User::ROLE_ADMINISTRATOR,
            'status' => User::STATUS_ACTIVE,
        ]);
        TenantUser::factory()
            ->forTenant($otherTenant)
            ->forUser($otherAdministrator)
            ->active()
            ->create();
        $project = $this->createProject(
            $otherTenant,
            $otherAdministrator,
        );

        Sanctum::actingAs($actor);

        $response = $this->getJson("/api/projects/{$project->id}")
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'المورد المطلوب غير متاح.',
                'error' => [
                    'code' => 'resource_not_found',
                    'message' => 'المورد المطلوب غير متاح.',
                ],
            ]);

        $payload = (string) $response->getContent();
        self::assertStringNotContainsString((string) $otherTenant->id, $payload);
        self::assertStringNotContainsString((string) $project->id, $payload);
    }

    public function test_validation_response_preserves_the_laravel_field_error_shape(): void
    {
        $administrator = $this->createActiveUser([
            'role' => User::ROLE_ADMINISTRATOR,
        ]);

        Sanctum::actingAs($administrator);

        $response = $this->postJson('/api/projects', [])
            ->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors' => [
                    'name',
                    'project_type',
                    'city',
                ],
            ]);

        self::assertArrayNotHasKey('error', $response->json());
    }

    public function test_generic_http_conflict_uses_the_canonical_state_conflict_contract(): void
    {
        Route::get('/api/testing/state-conflict', static function (): never {
            throw new ConflictHttpException('Internal state detail.');
        });

        $response = $this->getJson('/api/testing/state-conflict')
            ->assertConflict()
            ->assertJsonPath('error.code', 'state_conflict');

        self::assertSame(
            $response->json('message'),
            $response->json('error.message'),
        );
    }

    public function test_unexpected_api_exception_is_generic_and_does_not_leak_internals(): void
    {
        Route::get('/api/testing/internal-error', static function (): never {
            throw new RuntimeException(
                'SQLSTATE[42P01]: relation system_settings does not exist at /srv/private/Application.php:77'
            );
        });

        $response = $this->getJson('/api/testing/internal-error')
            ->assertInternalServerError()
            ->assertExactJson([
                'message' => 'تعذر إكمال العملية.',
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'تعذر إكمال العملية.',
                ],
            ]);

        $payload = (string) $response->getContent();
        self::assertStringNotContainsString('SQLSTATE', $payload);
        self::assertStringNotContainsString('system_settings', $payload);
        self::assertStringNotContainsString('/srv/private', $payload);
        self::assertStringNotContainsString(RuntimeException::class, $payload);
    }

    /** @return array<string, string> */
    private function projectPayload(): array
    {
        return [
            'name' => 'مشروع اختبار عقد الخطأ',
            'project_type' => 'residential',
            'city' => 'الرياض',
        ];
    }

    private function createProject(Tenant $tenant, User $creator): Project
    {
        $year = 2026;
        $sequence = (int) Project::query()
            ->where('project_number_year', $year)
            ->max('project_sequence_number') + 1;

        return Project::query()->create([
            'tenant_id' => $tenant->id,
            'project_number' => "ERR-{$year}-{$sequence}",
            'project_number_year' => $year,
            'project_sequence_number' => $sequence,
            'name' => 'مشروع مستأجر آخر',
            'project_type' => 'residential',
            'status' => 'draft',
            'country_code' => 'SA',
            'city' => 'الرياض',
            'currency' => 'SAR',
            'data_origin' => 'user',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }
}
