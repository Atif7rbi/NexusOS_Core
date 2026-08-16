<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Models\LeadActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesLeadFixtures;

final class LeadFollowUpsApiTest extends ApiTestCase
{
    use CreatesLeadFixtures;

    private string $originalTimezone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->freezeCrmClock(
            CarbonImmutable::parse('2026-08-05T06:00:00Z'),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }

    public function test_assigned_sales_can_schedule_and_reschedule_follow_up(): void
    {
        $tenant = $this->createLeadTenant();
        $sales = $this->createLeadUser(
            $tenant,
            User::ROLE_SALES,
        )['user'];
        $lead = $this->createLead($tenant, $sales);

        Sanctum::actingAs($sales);

        $scheduleAt = '2026-08-06T09:30:00+03:00';

        $this->postJson("/api/leads/{$lead->id}/follow-up", [
            'next_follow_up_at' => $scheduleAt,
            'next_action_type' => 'call',
            'next_action_note' => '  Confirm viewing appointment.  ',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.lead.next_follow_up_at',
                '2026-08-06T06:30:00.000000Z',
            )
            ->assertJsonPath('data.lead.next_action_type', 'call')
            ->assertJsonPath(
                'data.lead.next_action_note',
                'Confirm viewing appointment.',
            )
            ->assertJsonPath('data.lead.updated_by', $sales->id)
            ->assertJsonPath(
                'data.lead.allowed_actions.can_schedule_follow_up',
                false,
            )
            ->assertJsonPath(
                'data.lead.allowed_actions.can_reschedule_follow_up',
                true,
            );

        $lead->refresh();

        $this->assertSame($sales->id, $lead->updated_by);
        $this->assertSame('call', $lead->next_action_type?->value);
        $this->assertSame(
            'Confirm viewing appointment.',
            $lead->next_action_note,
        );

        $scheduledActivity = LeadActivity::query()
            ->where('lead_id', $lead->id)
            ->where('type', ActivityType::FollowUpScheduled->value)
            ->sole();

        $this->assertSame($sales->id, $scheduledActivity->created_by);
        $this->assertNull(
            $scheduledActivity->payload['previous_follow_up_at'],
        );
        $this->assertSame(
            '2026-08-06T06:30:00.000000Z',
            $scheduledActivity->payload['new_follow_up_at'],
        );
        $this->assertSame(
            'call',
            $scheduledActivity->payload['new_action_type'],
        );

        $this->freezeCrmClock(
            CarbonImmutable::parse('2026-08-05T06:15:00Z'),
        );

        $rescheduleAt = '2026-08-07T14:00:00+03:00';

        $this->patchJson("/api/leads/{$lead->id}/follow-up", [
            'next_follow_up_at' => $rescheduleAt,
            'next_action_type' => 'meeting',
            'next_action_note' => 'Discuss final offer.',
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.lead.next_follow_up_at',
                '2026-08-07T11:00:00.000000Z',
            )
            ->assertJsonPath('data.lead.next_action_type', 'meeting')
            ->assertJsonPath(
                'data.lead.next_action_note',
                'Discuss final offer.',
            )
            ->assertJsonPath('data.lead.updated_by', $sales->id);

        $lead->refresh();

        $this->assertSame($sales->id, $lead->updated_by);
        $this->assertSame('meeting', $lead->next_action_type?->value);

        $rescheduledActivity = LeadActivity::query()
            ->where('lead_id', $lead->id)
            ->where('type', ActivityType::FollowUpRescheduled->value)
            ->sole();

        $this->assertSame(
            '2026-08-06T06:30:00.000000Z',
            $rescheduledActivity->payload['previous_follow_up_at'],
        );
        $this->assertSame(
            '2026-08-07T11:00:00.000000Z',
            $rescheduledActivity->payload['new_follow_up_at'],
        );
        $this->assertSame(
            'call',
            $rescheduledActivity->payload['previous_action_type'],
        );
        $this->assertSame(
            'meeting',
            $rescheduledActivity->payload['new_action_type'],
        );
    }

    public function test_complete_and_cancel_clear_current_state_and_append_activity(): void
    {
        foreach ([
            'complete' => ActivityType::FollowUpCompleted,
            'cancel' => ActivityType::FollowUpCancelled,
        ] as $operation => $activityType) {
            $tenant = $this->createLeadTenant();
            $administrator = $this->createLeadUser(
                $tenant,
                User::ROLE_ADMINISTRATOR,
            )['user'];

            $lead = $this->createLead($tenant, $administrator, [
                'next_follow_up_at' => CarbonImmutable::parse(
                    '2026-08-06T06:30:00Z',
                ),
                'next_action_type' => 'whatsapp',
                'next_action_note' => 'Send project location.',
            ]);

            Sanctum::actingAs($administrator);

            $this->postJson(
                "/api/leads/{$lead->id}/follow-up/{$operation}",
                ['note' => '  Operation note.  '],
            )
                ->assertOk()
                ->assertJsonPath('data.lead.next_follow_up_at', null)
                ->assertJsonPath('data.lead.next_action_type', null)
                ->assertJsonPath('data.lead.next_action_note', null)
                ->assertJsonPath(
                    'data.lead.updated_by',
                    $administrator->id,
                )
                ->assertJsonPath(
                    'data.lead.allowed_actions.can_schedule_follow_up',
                    true,
                );

            $lead->refresh();

            $this->assertNull($lead->next_follow_up_at);
            $this->assertNull($lead->next_action_type);
            $this->assertNull($lead->next_action_note);
            $this->assertSame($administrator->id, $lead->updated_by);

            $activity = LeadActivity::query()
                ->where('lead_id', $lead->id)
                ->where('type', $activityType->value)
                ->sole();

            $this->assertSame(
                '2026-08-06T06:30:00.000000Z',
                $activity->payload['previous_follow_up_at'],
            );
            $this->assertSame(
                'whatsapp',
                $activity->payload['previous_action_type'],
            );
            $this->assertSame(
                'Send project location.',
                $activity->payload['previous_action_note'],
            );
            $this->assertNull($activity->payload['new_follow_up_at']);
            $this->assertNull($activity->payload['new_action_type']);
            $this->assertNull($activity->payload['new_action_note']);
            $this->assertSame(
                'Operation note.',
                $activity->payload['note'],
            );
        }
    }

    public function test_follow_up_request_validation_and_semantic_conflicts_are_enforced(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $lead = $this->createLead($tenant, $administrator);

        Sanctum::actingAs($administrator);

        $this->postJson("/api/leads/{$lead->id}/follow-up", [
            'next_follow_up_at' => '2026-08-06T09:30:00',
            'next_action_type' => 'call',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('next_follow_up_at');

        $this->postJson("/api/leads/{$lead->id}/follow-up", [
            'next_follow_up_at' => '2026-08-06T09:30:00+03:00',
            'next_action_type' => 'other',
            'next_action_note' => '   ',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('next_action_note');

        $payload = [
            'next_follow_up_at' => '2026-08-06T09:30:00+03:00',
            'next_action_type' => 'call',
            'next_action_note' => null,
        ];

        $this->postJson(
            "/api/leads/{$lead->id}/follow-up",
            $payload,
        )->assertOk();

        $this->postJson(
            "/api/leads/{$lead->id}/follow-up",
            $payload,
        )
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_follow_up_conflict');

        $this->patchJson(
            "/api/leads/{$lead->id}/follow-up",
            $payload,
        )
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_follow_up_conflict');
    }

    public function test_follow_up_writes_require_assignment_open_state_and_tenant_visibility(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $sales = $this->createLeadUser(
            $tenant,
            User::ROLE_SALES,
        )['user'];

        $unassigned = $this->createLead($tenant, $administrator, [
            'assigned_to' => null,
        ]);
        $won = $this->createLead($tenant, $administrator, [
            'stage' => LeadStage::Won,
        ]);
        $lost = $this->createLead($tenant, $administrator, [
            'stage' => LeadStage::Lost,
        ]);
        $archived = $this->createLead($tenant, $administrator, [
            'archived_at' => now(),
        ]);

        Sanctum::actingAs($sales);

        $this->postJson(
            "/api/leads/{$unassigned->id}/follow-up",
            $this->schedulePayload(),
        )
            ->assertForbidden()
            ->assertJsonPath(
                'error.code',
                'lead_action_not_authorized',
            );

        Sanctum::actingAs($administrator);

        foreach ([$won, $lost, $archived] as $blockedLead) {
            $this->postJson(
                "/api/leads/{$blockedLead->id}/follow-up",
                $this->schedulePayload(),
            )->assertConflict();
        }

        $otherTenant = $this->createLeadTenant();
        $otherAdministrator = $this->createLeadUser(
            $otherTenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];

        Sanctum::actingAs($otherAdministrator);

        $this->postJson(
            "/api/leads/{$unassigned->id}/follow-up",
            $this->schedulePayload(),
        )
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /**
     * @return array{
     *     next_follow_up_at: string,
     *     next_action_type: string,
     *     next_action_note: string
     * }
     */
    private function schedulePayload(): array
    {
        return [
            'next_follow_up_at' => '2026-08-06T09:30:00+03:00',
            'next_action_type' => 'call',
            'next_action_note' => 'Call customer.',
        ];
    }

    private function freezeCrmClock(CarbonImmutable $time): void
    {
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
    }
}
