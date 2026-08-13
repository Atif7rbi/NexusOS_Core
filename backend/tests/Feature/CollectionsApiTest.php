<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Collections\Actions\AmendCollectionScheduleAction;
use App\Modules\Collections\Actions\FinalizeCollectionScheduleAction;
use App\Modules\Collections\Actions\SaveDraftCollectionScheduleAction;
use App\Modules\Collections\Enums\CollectionStatus;
use App\Modules\Collections\Models\Collection;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesCollectionScheduleFixtures;

final class CollectionsApiTest extends ApiTestCase
{
    use CreatesCollectionScheduleFixtures;

    public function test_get_returns_the_absent_contract_scoped_resource_without_history_by_default(): void
    {
        $context = $this->apiContext(User::ROLE_ADMINISTRATOR);

        $response = $this->getJson($this->scheduleUrl($context));

        $response->assertOk()
            ->assertJsonPath('data.contract.id', $context['contract_id'])
            ->assertJsonPath('data.contract.status', 'draft')
            ->assertJsonPath('data.contract.currency', 'SAR')
            ->assertJsonPath('data.contract.total_amount', '1000.00')
            ->assertJsonPath('data.schedule.derived_state', 'absent')
            ->assertJsonPath('data.schedule.active_total', '0.00')
            ->assertJsonPath('data.schedule.active_collections', [])
            ->assertJsonPath('data.allowed_actions.can_save_draft', true)
            ->assertJsonPath('data.allowed_actions.can_finalize', false)
            ->assertJsonPath('data.allowed_actions.can_amend', false)
            ->assertJsonMissingPath('data.schedule.cancelled_history');
    }

    public function test_get_include_history_is_explicit_and_invalid_query_values_are_rejected(): void
    {
        $context = $this->apiContext(User::ROLE_ADMINISTRATOR);

        $this->getJson($this->scheduleUrl($context).'?include_history=true')
            ->assertOk()
            ->assertJsonPath('data.schedule.cancelled_history', []);

        $this->getJson($this->scheduleUrl($context).'?include_history=false')
            ->assertOk()
            ->assertJsonMissingPath('data.schedule.cancelled_history');

        $this->getJson($this->scheduleUrl($context).'?include_history=yes')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_query_parameter');

        $this->getJson($this->scheduleUrl($context).'?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'unexpected_request_fields');
    }

    public function test_save_draft_uses_post_and_returns_the_complete_updated_resource(): void
    {
        $context = $this->apiContext(User::ROLE_SALES);
        $url = $this->scheduleUrl($context).'/draft';

        $this->putJson($url, ['lines' => []])->assertStatus(405);

        $response = $this->postJson($url, [
            'lines' => [
                $this->linePayload(1, '400.00', '2026-08-01', 'بند التحصيل الأول'),
                $this->linePayload(2, '500.00', '2026-09-01', 'بند التحصيل الثاني'),
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'تم حفظ مسودة جدول التحصيل بنجاح.')
            ->assertJsonPath('data.schedule.derived_state', 'draft')
            ->assertJsonPath('data.schedule.active_total', '900.00')
            ->assertJsonCount(2, 'data.schedule.active_collections')
            ->assertJsonPath('data.schedule.active_collections.0.status', 'draft')
            ->assertJsonPath('data.schedule.active_collections.0.scheduled_at', null)
            ->assertJsonPath('data.schedule.active_collections.0.scheduled_by', null)
            ->assertJsonPath('data.allowed_actions.can_save_draft', true)
            ->assertJsonPath('data.allowed_actions.can_finalize', false)
            ->assertJsonMissingPath('data.schedule.cancelled_history');
    }

    public function test_system_owner_has_administrator_collection_authority(): void
    {
        $context = $this->apiContext(User::ROLE_SYSTEM_OWNER);

        $this->postJson($this->scheduleUrl($context).'/draft', [
            'lines' => [$this->linePayload(1, '1000.00', '2026-08-01')],
        ])->assertOk();

        $this->postJson($this->scheduleUrl($context).'/finalize')
            ->assertOk()
            ->assertJsonPath('data.schedule.derived_state', 'scheduled');
    }

    public function test_save_draft_empty_lines_deletes_an_existing_draft_and_returns_absent(): void
    {
        $context = $this->apiContext(User::ROLE_ADMINISTRATOR);
        $url = $this->scheduleUrl($context).'/draft';

        $this->postJson($url, [
            'lines' => [$this->linePayload(1, '1000.00', '2026-08-01')],
        ])->assertOk();

        $this->postJson($url, ['lines' => []])
            ->assertOk()
            ->assertJsonPath('data.schedule.derived_state', 'absent')
            ->assertJsonPath('data.schedule.active_total', '0.00')
            ->assertJsonPath('data.schedule.active_collections', []);
    }

    public function test_finalize_accepts_only_an_empty_body_and_returns_actor_objects(): void
    {
        $context = $this->apiContext(User::ROLE_ACCOUNTANT);
        $this->saveDraft($context);
        $url = $this->scheduleUrl($context).'/finalize';

        $this->postJson($url, ['confirm' => true])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'unexpected_request_fields');

        $response = $this->postJson($url);

        $response->assertOk()
            ->assertJsonPath('message', 'تم اعتماد جدول التحصيل بنجاح.')
            ->assertJsonPath('data.schedule.derived_state', 'scheduled')
            ->assertJsonPath('data.schedule.active_total', '1000.00')
            ->assertJsonPath('data.schedule.active_collections.0.status', 'scheduled')
            ->assertJsonPath('data.schedule.active_collections.0.scheduled_by.id', $context['user_id'])
            ->assertJsonPath('data.schedule.active_collections.0.scheduled_by.name', $context['user_name'])
            ->assertJsonPath('data.allowed_actions.can_save_draft', false)
            ->assertJsonPath('data.allowed_actions.can_finalize', false)
            ->assertJsonPath('data.allowed_actions.can_amend', true)
            ->assertJsonMissingPath('data.schedule.cancelled_history');

        $this->assertStringEndsWith('Z', (string) $response->json('data.schedule.active_collections.0.scheduled_at'));
    }

    public function test_amend_requires_generation_token_and_returns_replacement_without_history(): void
    {
        $context = $this->apiContext(User::ROLE_ACCOUNTANT);
        $this->saveAndFinalizeThroughDomain($context);
        $expectedIds = $this->activeIds($context);
        $url = $this->scheduleUrl($context).'/amend';

        $this->postJson($url, [
            'lines' => [$this->linePayload(1, '1000.00', '2026-10-01')],
            'cancellation_reason' => 'إعادة جدولة',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_expected_active_collection_ids');

        $response = $this->postJson($url, [
            'expected_active_collection_ids' => array_reverse($expectedIds),
            'lines' => [
                $this->linePayload(1, '250.00', '2026-10-01', 'بند بديل أول'),
                $this->linePayload(2, '750.00', '2026-11-01', 'بند بديل ثان'),
            ],
            'cancellation_reason' => '  اتفاق جديد  ',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'تم استبدال جدول التحصيل بنجاح.')
            ->assertJsonPath('data.schedule.derived_state', 'scheduled')
            ->assertJsonPath('data.schedule.active_total', '1000.00')
            ->assertJsonCount(2, 'data.schedule.active_collections')
            ->assertJsonMissingPath('data.schedule.cancelled_history');

        $replacementIds = collect($response->json('data.schedule.active_collections'))->pluck('id')->all();
        $this->assertSame([], array_values(array_intersect($expectedIds, $replacementIds)));

        $history = $this->getJson($this->scheduleUrl($context).'?include_history=true')
            ->assertOk()
            ->assertJsonCount(2, 'data.schedule.cancelled_history')
            ->assertJsonPath('data.schedule.cancelled_history.0.cancellation_reason', 'اتفاق جديد')
            ->assertJsonPath('data.schedule.cancelled_history.0.cancelled_by.id', $context['user_id'])
            ->assertJsonPath('data.schedule.cancelled_history.0.cancelled_by.name', $context['user_name'])
            ->assertJsonPath('data.schedule.cancelled_history.0.scheduled_by.id', $context['user_id']);

        $this->assertStringEndsWith('Z', (string) $history->json('data.schedule.cancelled_history.0.cancelled_at'));
        $this->assertStringEndsWith('Z', (string) $history->json('data.schedule.cancelled_history.0.scheduled_at'));
    }

    public function test_stale_amend_generation_returns_conflict_without_mutating_the_current_schedule(): void
    {
        $context = $this->apiContext(User::ROLE_ADMINISTRATOR);
        $this->saveAndFinalizeThroughDomain($context);
        $staleIds = $this->activeIds($context);

        (new AmendCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            $staleIds,
            [$this->collectionLine(null, 1, '1000.00', '2026-10-01')],
            'تعديل متزامن',
        );
        $currentIds = $this->activeIds($context);

        $this->postJson($this->scheduleUrl($context).'/amend', [
            'expected_active_collection_ids' => $staleIds,
            'lines' => [$this->linePayload(1, '1000.00', '2026-11-01')],
            'cancellation_reason' => 'طلب قديم',
        ])->assertConflict()
            ->assertJsonPath('error.code', 'collection_schedule_changed_since_loaded');

        $this->assertSame($currentIds, $this->activeIds($context));
    }

    public function test_role_authorization_matches_the_frozen_financial_matrix(): void
    {
        $sales = $this->apiContext(User::ROLE_SALES);
        $this->saveDraft($sales);

        $this->postJson($this->scheduleUrl($sales).'/finalize')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'role_not_authorized');

        (new FinalizeCollectionScheduleAction)->execute(
            $sales['tenant_id'],
            $sales['contract_id'],
            $sales['user_id'],
        );
        $this->postJson($this->scheduleUrl($sales).'/amend', [
            'expected_active_collection_ids' => $this->activeIds($sales),
            'lines' => [$this->linePayload(1, '1000.00', '2026-10-01')],
            'cancellation_reason' => 'غير مصرح',
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'role_not_authorized');

        $projectManager = $this->apiContext(User::ROLE_PROJECT_MANAGER);

        $this->getJson($this->scheduleUrl($projectManager))
            ->assertOk()
            ->assertJsonPath('data.allowed_actions.can_save_draft', false);

        $this->postJson($this->scheduleUrl($projectManager).'/draft', [
            'lines' => [$this->linePayload(1, '1000.00', '2026-08-01')],
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'role_not_authorized');

        $employee = $this->apiContext(User::ROLE_EMPLOYEE);

        $this->getJson($this->scheduleUrl($employee))->assertOk();
        $this->postJson($this->scheduleUrl($employee).'/draft', [
            'lines' => [$this->linePayload(1, '1000.00', '2026-08-01')],
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'role_not_authorized');

        $systemOwner = $this->apiContext(User::ROLE_SYSTEM_OWNER);

        $this->getJson($this->scheduleUrl($systemOwner))->assertOk();
        $this->postJson($this->scheduleUrl($systemOwner).'/draft', [
            'lines' => [$this->linePayload(1, '1000.00', '2026-08-01')],
        ])->assertOk();
    }

    public function test_tenant_membership_failures_keep_the_existing_frozen_error_codes(): void
    {
        $contractId = (string) Str::ulid();
        $users = [
            [$this->createPausedUser(), 'tenant_membership_paused'],
            [$this->createSuspendedUser(), 'tenant_membership_suspended'],
            [$this->createRemovedUser(), 'tenant_membership_removed'],
            [User::factory()->create([
                'status' => User::STATUS_ACTIVE,
                'role' => User::ROLE_ADMINISTRATOR,
            ]), 'tenant_membership_missing'],
        ];

        foreach ($users as [$user, $code]) {
            Sanctum::actingAs($user);

            $this->getJson("/api/contracts/{$contractId}/collection-schedule")
                ->assertForbidden()
                ->assertJsonPath('error.code', $code);
        }
    }

    public function test_cross_tenant_contract_is_hidden_before_role_authorization(): void
    {
        $owner = $this->apiContext(User::ROLE_ADMINISTRATOR);
        $foreign = $this->apiContext(User::ROLE_EMPLOYEE);

        $this->getJson("/api/contracts/{$owner['contract_id']}/collection-schedule")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'contract_not_found');

        $this->postJson("/api/contracts/{$owner['contract_id']}/collection-schedule/draft", [
            'lines' => [$this->linePayload(1, '1000.00', '2026-08-01')],
        ])->assertNotFound()
            ->assertJsonPath('error.code', 'contract_not_found');

        $this->assertNotSame($owner['tenant_id'], $foreign['tenant_id']);
    }

    public function test_unauthenticated_request_uses_the_frozen_error_code(): void
    {
        $context = $this->apiContext(User::ROLE_ADMINISTRATOR);
        app('auth')->forgetGuards();

        $this->getJson($this->scheduleUrl($context))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_request_validation_returns_specific_frozen_error_codes(): void
    {
        $context = $this->apiContext(User::ROLE_ADMINISTRATOR);
        $url = $this->scheduleUrl($context).'/draft';

        $cases = [
            [['lines' => [['sequence' => 0, 'title' => 'بند', 'amount' => '1.00', 'due_date' => '2026-08-01']]], 'invalid_collection_sequence'],
            [['lines' => [['sequence' => 1, 'title' => '   ', 'amount' => '1.00', 'due_date' => '2026-08-01']]], 'blank_collection_title'],
            [['lines' => [['sequence' => 1, 'title' => str_repeat('أ', 151), 'amount' => '1.00', 'due_date' => '2026-08-01']]], 'collection_title_too_long'],
            [['lines' => [['sequence' => 1, 'title' => 'بند', 'amount' => '0.00', 'due_date' => '2026-08-01']]], 'invalid_collection_amount'],
            [['lines' => [['sequence' => 1, 'title' => 'بند', 'amount' => '1.001', 'due_date' => '2026-08-01']]], 'excess_decimal_precision'],
            [['lines' => [['id' => 'not-a-ulid', 'sequence' => 1, 'title' => 'بند', 'amount' => '1.00', 'due_date' => '2026-08-01']]], 'invalid_collection_reference'],
            [['lines' => [['sequence' => 1, 'title' => 'بند', 'amount' => '1.00', 'due_date' => '2026-08-01', 'currency' => 'SAR']]], 'unexpected_request_fields'],
        ];

        foreach ($cases as [$payload, $code]) {
            $this->postJson($url, $payload)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', $code);
        }
    }

    public function test_domain_validation_and_integrity_exceptions_are_mapped_without_generic_conflicts(): void
    {
        $context = $this->apiContext(User::ROLE_ADMINISTRATOR);

        $this->postJson($this->scheduleUrl($context).'/draft', [
            'lines' => [
                $this->linePayload(1, '500.00', '2026-09-01'),
                $this->linePayload(1, '500.00', '2026-10-01'),
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'duplicate_collection_sequence');

        $this->postJson($this->scheduleUrl($context).'/draft', [
            'lines' => [
                $this->linePayload(1, '500.00', '2026-10-01'),
                $this->linePayload(2, '500.00', '2026-09-01'),
            ],
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'non_decreasing_due_date_violation');

        $this->postJson($this->scheduleUrl($context).'/draft', [
            'lines' => [$this->linePayload(1, '999.00', '2026-08-01')],
        ])->assertOk();

        $this->postJson($this->scheduleUrl($context).'/finalize')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'schedule_total_mismatch');
    }

    public function test_invalid_mixed_active_state_is_never_returned_as_success(): void
    {
        $context = $this->apiContext(User::ROLE_ADMINISTRATOR);

        Collection::query()->create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'sequence' => 1,
            'title' => 'مسودة',
            'amount' => '500.00',
            'due_date' => '2026-08-01',
            'status' => CollectionStatus::Draft,
            'created_by' => $context['user_id'],
        ]);
        Collection::query()->create([
            'id' => (string) Str::ulid(),
            'tenant_id' => $context['tenant_id'],
            'contract_id' => $context['contract_id'],
            'sequence' => 2,
            'title' => 'مجدول',
            'amount' => '500.00',
            'due_date' => '2026-09-01',
            'status' => CollectionStatus::Scheduled,
            'scheduled_at' => now(),
            'scheduled_by' => $context['user_id'],
            'created_by' => $context['user_id'],
        ]);

        $this->getJson($this->scheduleUrl($context))
            ->assertConflict()
            ->assertJsonPath('error.code', 'collection_schedule_integrity_violation');
    }

    /**
     * @return array{tenant_id: string, contract_id: string, user_id: int, user_name: string}
     */
    private function apiContext(string $role): array
    {
        $context = $this->createCollectionContractContext();
        $user = User::query()->findOrFail($context['user_id']);
        $user->forceFill(['role' => $role])->save();
        Sanctum::actingAs($user->refresh());

        return [
            ...$context,
            'user_name' => (string) $user->name,
        ];
    }

    /** @param array{contract_id: string} $context */
    private function scheduleUrl(array $context): string
    {
        return "/api/contracts/{$context['contract_id']}/collection-schedule";
    }

    /** @return array<string, mixed> */
    private function linePayload(
        int $sequence,
        string $amount,
        string $dueDate,
        string $title = 'بند تحصيل',
        ?string $notes = null,
    ): array {
        return [
            'sequence' => $sequence,
            'title' => $title,
            'amount' => $amount,
            'due_date' => $dueDate,
            'notes' => $notes,
        ];
    }

    /** @param array{tenant_id: string, contract_id: string, user_id: int} $context */
    private function saveDraft(array $context): void
    {
        $this->postJson($this->scheduleUrl($context).'/draft', [
            'lines' => [
                $this->linePayload(1, '400.00', '2026-08-01'),
                $this->linePayload(2, '600.00', '2026-09-01'),
            ],
        ])->assertOk();
    }

    /** @param array{tenant_id: string, contract_id: string, user_id: int} $context */
    private function saveAndFinalizeThroughDomain(array $context): void
    {
        (new SaveDraftCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
            [
                $this->collectionLine(null, 1, '400.00', '2026-08-01'),
                $this->collectionLine(null, 2, '600.00', '2026-09-01'),
            ],
        );
        (new FinalizeCollectionScheduleAction)->execute(
            $context['tenant_id'],
            $context['contract_id'],
            $context['user_id'],
        );
    }

    /** @param array{tenant_id: string, contract_id: string} $context
     *  @return array<int, string>
     */
    private function activeIds(array $context): array
    {
        return Collection::query()
            ->where('tenant_id', $context['tenant_id'])
            ->where('contract_id', $context['contract_id'])
            ->where('status', '!=', CollectionStatus::Cancelled->value)
            ->orderBy('sequence')
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }
}
