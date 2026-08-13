<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Enums\ArchiveReason;
use App\Modules\Leads\Enums\LeadSource;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Enums\LostReason;
use App\Modules\Leads\Models\Lead;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesLeadFixtures;

final class LeadsApiTest extends ApiTestCase
{
    use CreatesLeadFixtures;

    private string $originalTimezone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->freezeCrmClock(CarbonImmutable::parse('2026-08-02T12:00:00Z'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }

    public function test_guest_and_non_crm_roles_cannot_access_leads(): void
    {
        $this->getJson('/api/leads')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $tenant = $this->createLeadTenant();
        foreach ([User::ROLE_ACCOUNTANT, User::ROLE_EMPLOYEE] as $role) {
            $actor = $this->createLeadUser($tenant, $role)['user'];
            Sanctum::actingAs($actor);

            $this->getJson('/api/leads')
                ->assertForbidden()
                ->assertJsonPath('error.code', 'lead_action_not_authorized');
        }
    }

    public function test_sales_and_project_manager_creation_normalizes_phone_and_auto_assigns_actor(): void
    {
        foreach ([
            User::ROLE_SALES => "\t٠٥٠١٢٣٤٥٦٧ ",
            User::ROLE_PROJECT_MANAGER => '۰۵۰۱۲۳۴۵۶۸',
        ] as $role => $phone) {
            $tenant = $this->createLeadTenant();
            $actor = $this->createLeadUser($tenant, $role)['user'];
            Sanctum::actingAs($actor);

            $expected = $role === User::ROLE_SALES ? '0501234567' : '0501234568';
            $response = $this->postJson('/api/leads', $this->createPayload($phone));

            $response
                ->assertCreated()
                ->assertJsonPath('data.lead.phone', $expected)
                ->assertJsonPath('data.lead.stage', LeadStage::New->value)
                ->assertJsonPath('data.lead.assigned_to.id', $actor->id)
                ->assertJsonPath('data.lead.created_by', $actor->id)
                ->assertJsonPath('data.lead.updated_by', $actor->id);

            $this->assertDatabaseHas('leads', [
                'tenant_id' => $tenant->id,
                'phone' => $expected,
                'stage' => LeadStage::New->value,
                'assigned_to' => $actor->id,
            ]);
        }
    }

    public function test_administrator_may_create_unassigned_or_assign_an_eligible_user(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $sales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $projectManager = $this->createLeadUser(
            $tenant,
            User::ROLE_PROJECT_MANAGER,
        )['user'];
        Sanctum::actingAs($administrator);

        $this->postJson('/api/leads', $this->createPayload($this->nextLeadPhone()))
            ->assertCreated()
            ->assertJsonPath('data.lead.assigned_to', null);

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['assigned_to' => $sales->id],
        ))
            ->assertCreated()
            ->assertJsonPath('data.lead.assigned_to.id', $sales->id);

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['assigned_to' => $projectManager->id],
        ))
            ->assertCreated()
            ->assertJsonPath('data.lead.assigned_to.id', $projectManager->id);
    }

    public function test_system_owner_has_administrator_crm_authority(): void
    {
        $tenant = $this->createLeadTenant();
        $owner = $this->createLeadUser(
            $tenant,
            User::ROLE_SYSTEM_OWNER,
        )['user'];
        $sales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        Sanctum::actingAs($owner);

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['assigned_to' => $sales->id],
        ))
            ->assertCreated()
            ->assertJsonPath('data.lead.assigned_to.id', $sales->id);
    }

    public function test_sales_and_project_manager_cannot_supply_assigned_to_on_create(): void
    {
        foreach ([User::ROLE_SALES, User::ROLE_PROJECT_MANAGER] as $role) {
            $tenant = $this->createLeadTenant();
            $actor = $this->createLeadUser($tenant, $role)['user'];
            Sanctum::actingAs($actor);

            $this->postJson('/api/leads', array_merge(
                $this->createPayload($this->nextLeadPhone()),
                ['assigned_to' => $actor->id],
            ))
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_error')
                ->assertJsonValidationErrors('assigned_to');
        }
    }

    public function test_create_request_rejects_stage_unknown_fields_and_invalid_source_detail(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        Sanctum::actingAs($administrator);

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['stage' => 'qualified'],
        ))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');

        $this->postJson('/api/leads', [
            'name' => 'Other Source',
            'phone' => $this->nextLeadPhone(),
            'source' => LeadSource::Other->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_detail');

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['unknown_field' => true],
        ))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error');

        $this->postJson('/api/leads', $this->createPayload('+966501234567'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonValidationErrors('phone');
    }

    public function test_administrator_assignment_requires_an_active_eligible_same_tenant_user(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $paused = $this->createLeadUser(
            $tenant,
            User::ROLE_SALES,
            TenantUser::STATUS_PAUSED,
        )['user'];
        $ineligible = $this->createLeadUser(
            $tenant,
            User::ROLE_ACCOUNTANT,
        )['user'];
        $otherTenant = $this->createLeadTenant();
        $otherUser = $this->createLeadUser($otherTenant, User::ROLE_SALES)['user'];
        Sanctum::actingAs($administrator);

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['assigned_to' => $paused->id],
        ))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'lead_assignee_not_active');

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['assigned_to' => $ineligible->id],
        ))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'lead_assignee_role_not_eligible');

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['assigned_to' => $otherUser->id],
        ))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'lead_assignee_not_active');
    }

    public function test_administrative_roles_cannot_be_selected_as_lead_assignees(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        Sanctum::actingAs($administrator);

        foreach ([User::ROLE_SYSTEM_OWNER, User::ROLE_ADMINISTRATOR] as $role) {
            $ineligible = $role === User::ROLE_ADMINISTRATOR
                ? $administrator
                : $this->createLeadUser($tenant, $role)['user'];

            $this->postJson('/api/leads', array_merge(
                $this->createPayload($this->nextLeadPhone()),
                ['assigned_to' => $ineligible->id],
            ))
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'lead_assignee_role_not_eligible');
        }
    }

    public function test_duplicate_protocol_discloses_only_visible_matches_and_requires_acknowledgement(): void
    {
        $tenant = $this->createLeadTenant();
        $firstSales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $secondSales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $phone = $this->nextLeadPhone();

        Sanctum::actingAs($firstSales);
        $hiddenLeadId = $this->postJson('/api/leads', $this->createPayload($phone))
            ->assertCreated()
            ->json('data.lead.id');

        Sanctum::actingAs($secondSales);
        $visibleLeadId = $this->postJson('/api/leads', $this->createPayload($phone))
            ->assertCreated()
            ->json('data.lead.id');

        $conflict = $this->postJson('/api/leads', $this->createPayload($phone));
        $conflict
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_phone_duplicate_detected')
            ->assertJsonCount(1, 'error.matches')
            ->assertJsonPath('error.matches.0.id', $visibleLeadId);
        $this->assertNotSame($hiddenLeadId, $conflict->json('error.matches.0.id'));

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($phone),
            ['acknowledge_duplicate' => true],
        ))->assertCreated();

        $this->assertSame(3, Lead::query()
            ->where('tenant_id', $tenant->id)
            ->where('phone', $phone)
            ->count());
    }

    public function test_tenant_isolation_applies_to_list_show_and_mutation(): void
    {
        $firstTenant = $this->createLeadTenant();
        $firstAdmin = $this->createLeadUser(
            $firstTenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $lead = $this->createLead($firstTenant, $firstAdmin);

        $secondTenant = $this->createLeadTenant();
        $secondAdmin = $this->createLeadUser(
            $secondTenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        Sanctum::actingAs($secondAdmin);

        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 0);
        $this->getJson("/api/leads/{$lead->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'lead_not_found');
        $this->patchJson("/api/leads/{$lead->id}", ['name' => 'Cross tenant'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'lead_not_found');
    }

    public function test_final_visibility_rule_and_archived_mode_are_enforced(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $sales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $otherSales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];

        $ownOpen = $this->createLead($tenant, $sales);
        $ownLost = $this->createLead($tenant, $sales, ['stage' => 'lost']);
        $ownWon = $this->createLead($tenant, $sales, ['stage' => 'won']);
        $unassignedOpen = $this->createLead($tenant, $administrator, [
            'assigned_to' => null,
        ]);
        $unassignedLost = $this->createLead($tenant, $administrator, [
            'assigned_to' => null,
            'stage' => 'lost',
        ]);
        $unassignedWon = $this->createLead($tenant, $administrator, [
            'assigned_to' => null,
            'stage' => 'won',
        ]);
        $assignedToOther = $this->createLead($tenant, $otherSales);
        $archivedOwn = $this->createLead($tenant, $sales, [
            'archived_at' => now(),
        ]);

        Sanctum::actingAs($sales);
        $visibleIds = collect($this->getJson('/api/leads')
            ->assertOk()
            ->json('data.leads'))
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(collect([
            $ownOpen->id,
            $unassignedOpen->id,
        ])->sort()->values()->all(), $visibleIds);

        $this->getJson('/api/leads?lifecycle=won')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath(
                'data.leads.0.id',
                (string) $ownWon->id,
            );

        $this->getJson('/api/leads?lifecycle=lost')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath(
                'data.leads.0.id',
                (string) $ownLost->id,
            );

        $this->getJson("/api/leads/{$ownLost->id}")->assertOk();
        $this->getJson("/api/leads/{$ownWon->id}")->assertOk();
        $this->getJson("/api/leads/{$unassignedOpen->id}")
            ->assertOk()
            ->assertJsonPath('data.lead.allowed_actions.can_edit', false)
            ->assertJsonPath('data.lead.allowed_actions.can_claim', true);

        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.summary.open_leads', 2)
            ->assertJsonPath('data.summary.unassigned_leads', 1)
            ->assertJsonPath('data.summary.monthly_conversions', 1);

        $this->getJson('/api/leads?archived=true')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonMissing(['id' => (string) $archivedOwn->id]);

        foreach ([$unassignedLost, $unassignedWon, $assignedToOther, $archivedOwn] as $hidden) {
            $this->getJson("/api/leads/{$hidden->id}")
                ->assertNotFound()
                ->assertJsonPath('error.code', 'lead_not_found');
        }

        Sanctum::actingAs($administrator);
        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonMissing([
                'id' => (string) $ownWon->id,
            ])
            ->assertJsonMissing([
                'id' => (string) $ownLost->id,
            ]);

        $this->getJson('/api/leads?lifecycle=won')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $this->getJson('/api/leads?lifecycle=lost')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $archived = $this->getJson('/api/leads?archived=true')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->json('data.leads.0.id');
        $this->assertSame((string) $archivedOwn->id, $archived);
    }

    public function test_project_manager_uses_the_same_own_and_unassigned_open_visibility_contract_as_sales(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $projectManager = $this->createLeadUser(
            $tenant,
            User::ROLE_PROJECT_MANAGER,
        )['user'];
        $otherProjectManager = $this->createLeadUser(
            $tenant,
            User::ROLE_PROJECT_MANAGER,
        )['user'];
        $own = $this->createLead($tenant, $projectManager);
        $unassigned = $this->createLead($tenant, $administrator, ['assigned_to' => null]);
        $this->createLead($tenant, $otherProjectManager);
        Sanctum::actingAs($projectManager);

        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
        $this->patchJson("/api/leads/{$own->id}/stage", ['stage' => 'qualified'])
            ->assertOk();
        $this->patchJson("/api/leads/{$unassigned->id}/stage", ['stage' => 'qualified'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'lead_action_not_authorized');
    }

    public function test_search_filters_pagination_and_summary_obey_the_frozen_scope(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $project = $this->createLeadProject($tenant, $administrator);

        $overdueAt = CarbonImmutable::now('Asia/Riyadh')
            ->startOfDay()
            ->subMinute()
            ->utc();
        $overdue = $this->createLead($tenant, $administrator, [
            'name' => 'Overdue Search Lead',
            'source' => LeadSource::PhoneCall,
            'next_follow_up_at' => $overdueAt,
            'project_id' => $project->id,
        ]);
        $target = $this->createLead($tenant, $administrator, [
            'name' => 'Qualified Target',
            'source' => LeadSource::Referral,
            'stage' => LeadStage::Qualified,
            'project_id' => $project->id,
        ]);
        $this->createLead($tenant, $administrator, [
            'name' => 'Unassigned Open',
            'assigned_to' => null,
        ]);
        $this->createLead($tenant, $administrator, [
            'name' => 'Unassigned Lost',
            'assigned_to' => null,
            'stage' => LeadStage::Lost,
        ]);
        $this->createLead($tenant, $administrator, [
            'name' => 'Converted Lead',
            'stage' => LeadStage::Won,
        ]);
        Sanctum::actingAs($administrator);

        $response = $this->getJson('/api/leads?'.http_build_query([
            'search' => 'Qualified Target',
            'stage' => 'qualified',
            'source' => 'referral',
            'assigned_to' => $administrator->id,
            'project_id' => $project->id,
            'page' => 1,
            'per_page' => 1,
        ]));
        $response
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.leads.0.id', (string) $target->id)
            ->assertJsonPath('data.summary.open_leads', 3)
            ->assertJsonPath('data.summary.unassigned_leads', 1)
            ->assertJsonPath('data.summary.overdue_follow_ups', 1)
            ->assertJsonPath('data.summary.monthly_conversions', 1);

        $this->getJson('/api/leads?overdue=true')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.leads.0.id', (string) $overdue->id);

        $this->getJson('/api/leads?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonCount(1, 'data.leads');
    }

    public function test_final_operational_filters_and_summary_use_riyadh_boundaries(): void
    {
        $this->freezeCrmClock(
            CarbonImmutable::parse('2026-08-05T09:00:00Z'),
        );

        $tenant = $this->createLeadTenant([
            'timezone' => 'Asia/Riyadh',
        ]);
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];

        $overdue = $this->createLead($tenant, $administrator, [
            'name' => 'Overdue Lead',
            'next_follow_up_at' => CarbonImmutable::parse(
                '2026-08-04T20:59:59Z',
            ),
        ]);
        $today = $this->createLead($tenant, $administrator, [
            'name' => 'Today Lead',
            'next_follow_up_at' => CarbonImmutable::parse(
                '2026-08-05T08:00:00Z',
            ),
        ]);
        $tomorrow = $this->createLead($tenant, $administrator, [
            'name' => 'Tomorrow Lead',
            'next_follow_up_at' => CarbonImmutable::parse(
                '2026-08-06T08:00:00Z',
            ),
        ]);
        $thisWeek = $this->createLead($tenant, $administrator, [
            'name' => 'This Week Lead',
            'next_follow_up_at' => CarbonImmutable::parse(
                '2026-08-07T08:00:00Z',
            ),
        ]);
        $scheduledLater = $this->createLead($tenant, $administrator, [
            'name' => 'Scheduled Later Lead',
            'next_follow_up_at' => CarbonImmutable::parse(
                '2026-08-10T08:00:00Z',
            ),
        ]);
        $unscheduled = $this->createLead($tenant, $administrator, [
            'name' => 'Unscheduled Lead',
            'assigned_to' => null,
        ]);
        $won = $this->createLead($tenant, $administrator, [
            'name' => 'Won Lead',
            'stage' => LeadStage::Won,
        ]);
        $lost = $this->createLead($tenant, $administrator, [
            'name' => 'Lost Lead',
            'stage' => LeadStage::Lost,
        ]);

        $won->forceFill([
            'converted_at' => CarbonImmutable::parse(
                '2026-08-03T08:00:00Z',
            ),
        ])->save();

        $lost->forceFill([
            'lost_at' => CarbonImmutable::parse(
                '2026-08-03T08:00:00Z',
            ),
        ])->save();

        Sanctum::actingAs($administrator);

        $this->getJson('/api/leads?follow_up_state=overdue')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.leads.0.id', (string) $overdue->id);

        $this->getJson('/api/leads?follow_up_state=today')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.leads.0.id', (string) $today->id);

        $this->getJson('/api/leads?follow_up_state=tomorrow')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.leads.0.id', (string) $tomorrow->id);

        $this->getJson('/api/leads?follow_up_state=this_week')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.leads.0.id', (string) $thisWeek->id);

        $this->getJson('/api/leads?follow_up_state=scheduled_later')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath(
                'data.leads.0.id',
                (string) $scheduledLater->id,
            );

        $this->getJson('/api/leads?follow_up_state=unscheduled')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath(
                'data.leads.0.id',
                (string) $unscheduled->id,
            );

        $this->getJson('/api/leads?lifecycle=open')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 6);

        $this->getJson('/api/leads?lifecycle=won')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.leads.0.id', (string) $won->id);

        $this->getJson('/api/leads?lifecycle=lost')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.leads.0.id', (string) $lost->id);

        $this->getJson('/api/leads?'.http_build_query([
            'date_from' => '2026-08-06',
            'date_to' => '2026-08-07',
            'lifecycle' => 'open',
        ]))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonFragment(['id' => (string) $tomorrow->id])
            ->assertJsonFragment(['id' => (string) $thisWeek->id]);

        $this->getJson('/api/leads?'.http_build_query([
            'date_from' => '2026-08-03',
            'date_to' => '2026-08-03',
        ]))
            ->assertOk()
            ->assertJsonPath('data.summary.open_leads', 6)
            ->assertJsonPath('data.summary.overdue_follow_ups', 1)
            ->assertJsonPath('data.summary.today_follow_ups', 1)
            ->assertJsonPath('data.summary.unassigned_leads', 1)
            ->assertJsonPath('data.summary.monthly_conversions', 1)
            ->assertJsonPath(
                'data.summary.lost_leads_in_selected_period',
                1,
            );

        $this->getJson('/api/leads')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 6)
            ->assertJsonMissing([
                'id' => (string) $won->id,
            ])
            ->assertJsonMissing([
                'id' => (string) $lost->id,
            ])
            ->assertJsonPath(
                'data.summary.lost_leads_in_selected_period',
                1,
            );

        $this->getJson('/api/leads?stage=won')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath(
                'data.leads.0.id',
                (string) $won->id,
            );

        $this->getJson('/api/leads?stage=lost')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath(
                'data.leads.0.id',
                (string) $lost->id,
            );
    }

    public function test_final_operational_filter_contract_rejects_invalid_values(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        Sanctum::actingAs($administrator);

        $this->getJson('/api/leads?follow_up_state=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('follow_up_state');

        $this->getJson('/api/leads?lifecycle=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lifecycle');

        $this->getJson('/api/leads?date_from=05-08-2026')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');

        $this->getJson(
            '/api/leads?date_from=2026-08-06&date_to=2026-08-05',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_follow_up_filters_are_mutually_exclusive(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];

        Sanctum::actingAs($administrator);

        $this->getJson(
            '/api/leads?follow_up_state=today&follow_up_bucket=overdue',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'follow_up_state',
                'follow_up_bucket',
            ]);

        $this->getJson(
            '/api/leads?follow_up_state=today&overdue=true',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'follow_up_state',
                'overdue',
            ]);

        $this->getJson(
            '/api/leads?follow_up_bucket=today&overdue=false',
        )->assertOk();
    }

    public function test_follow_up_week_boundary_starts_at_monday_midnight_in_riyadh(): void
    {
        $this->freezeCrmClock(
            CarbonImmutable::parse('2026-08-05T09:00:00+03:00'),
        );

        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];

        $sundayLastSecond = $this->createLead(
            $tenant,
            $administrator,
            [
                'next_follow_up_at' => CarbonImmutable::parse(
                    '2026-08-09T20:59:59.999999Z',
                ),
            ],
        );

        $mondayStart = $this->createLead(
            $tenant,
            $administrator,
            [
                'next_follow_up_at' => CarbonImmutable::parse(
                    '2026-08-09T21:00:00Z',
                ),
            ],
        );

        Sanctum::actingAs($administrator);

        $this->getJson('/api/leads?follow_up_state=this_week')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath(
                'data.leads.0.id',
                (string) $sundayLastSecond->id,
            );

        $this->getJson('/api/leads?follow_up_state=scheduled_later')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath(
                'data.leads.0.id',
                (string) $mondayStart->id,
            );
    }

    public function test_project_and_unit_interest_validation_is_tenant_safe(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $firstProject = $this->createLeadProject($tenant, $administrator);
        $secondProject = $this->createLeadProject($tenant, $administrator);
        $unit = $this->createLeadUnit($tenant, $firstProject, $administrator);
        $archivedProject = $this->archiveLeadProject(
            $this->createLeadProject($tenant, $administrator),
            $administrator,
        );
        $archivedUnit = $this->archiveLeadUnit(
            $this->createLeadUnit($tenant, $firstProject, $administrator),
            $administrator,
        );
        $otherTenant = $this->createLeadTenant();
        $otherAdmin = $this->createLeadUser(
            $otherTenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $otherProject = $this->createLeadProject($otherTenant, $otherAdmin);
        Sanctum::actingAs($administrator);

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['project_id' => $firstProject->id, 'unit_id' => $unit->id],
        ))
            ->assertCreated()
            ->assertJsonPath('data.lead.project_id', (string) $firstProject->id)
            ->assertJsonPath('data.lead.unit_id', (string) $unit->id);

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['unit_id' => $unit->id],
        ))->assertUnprocessable()
            ->assertJsonPath('error.code', 'lead_unit_project_mismatch');

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['project_id' => $secondProject->id, 'unit_id' => $unit->id],
        ))->assertUnprocessable()
            ->assertJsonPath('error.code', 'lead_unit_project_mismatch');

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['project_id' => $archivedProject->id],
        ))->assertUnprocessable()
            ->assertJsonPath('error.code', 'lead_project_is_archived');

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['project_id' => $firstProject->id, 'unit_id' => $archivedUnit->id],
        ))->assertUnprocessable()
            ->assertJsonPath('error.code', 'lead_unit_is_archived');

        $this->postJson('/api/leads', array_merge(
            $this->createPayload($this->nextLeadPhone()),
            ['project_id' => $otherProject->id],
        ))->assertUnprocessable()
            ->assertJsonPath('error.code', 'lead_unit_project_mismatch');
    }

    public function test_open_assigned_lead_can_be_updated_and_project_change_clears_unit(): void
    {
        $tenant = $this->createLeadTenant();
        $sales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $firstProject = $this->createLeadProject($tenant, $sales);
        $secondProject = $this->createLeadProject($tenant, $sales);
        $unit = $this->createLeadUnit($tenant, $firstProject, $sales);
        $lead = $this->createLead($tenant, $sales, [
            'project_id' => $firstProject->id,
            'unit_id' => $unit->id,
        ]);
        Sanctum::actingAs($sales);
        $updatedAt = CarbonImmutable::parse('2026-08-02T13:00:00Z');
        $this->freezeCrmClock($updatedAt);

        $this->patchJson("/api/leads/{$lead->id}", [
            'name' => 'Updated Lead',
            'phone' => '٠٥٠١٢٣٤٥٦٩',
            'project_id' => $secondProject->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.lead.name', 'Updated Lead')
            ->assertJsonPath('data.lead.phone', '0501234569')
            ->assertJsonPath('data.lead.project_id', (string) $secondProject->id)
            ->assertJsonPath('data.lead.unit_id', null)
            ->assertJsonPath('data.lead.updated_by', $sales->id);

        $lead->refresh();
        $this->assertSame($updatedAt->toISOString(), $lead->updated_at?->toISOString());
    }

    public function test_generic_update_rejects_follow_up_fields(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $lead = $this->createLead($tenant, $administrator);

        Sanctum::actingAs($administrator);

        $this->patchJson("/api/leads/{$lead->id}", [
            'next_follow_up_at' => '2026-08-06T09:30:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath(
                'error.message',
                'The request contains fields that are not defined by the CRM Leads v1 API contract.',
            );

        $lead->refresh();

        $this->assertNull($lead->next_follow_up_at);
        $this->assertNull($lead->next_action_type);
        $this->assertNull($lead->next_action_note);
    }

    public function test_update_is_blocked_for_unassigned_closed_and_archived_leads(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $sales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $unassigned = $this->createLead($tenant, $administrator, ['assigned_to' => null]);
        $lost = $this->createLead($tenant, $sales, ['stage' => 'lost']);
        $archived = $this->createLead($tenant, $administrator, ['archived_at' => now()]);

        Sanctum::actingAs($sales);
        $this->patchJson("/api/leads/{$unassigned->id}", ['name' => 'Denied'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'lead_action_not_authorized');
        $this->patchJson("/api/leads/{$lost->id}", ['name' => 'Denied'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_not_in_open_stage');

        Sanctum::actingAs($administrator);
        $this->patchJson("/api/leads/{$archived->id}", ['name' => 'Denied'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_is_archived');
    }

    public function test_forward_and_administrator_backward_stage_transitions_write_activities(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $sales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $salesLead = $this->createLead($tenant, $sales);
        $adminLead = $this->createLead($tenant, $administrator);

        Sanctum::actingAs($sales);
        $this->patchJson("/api/leads/{$salesLead->id}/stage", ['stage' => 'qualified'])
            ->assertOk()
            ->assertJsonPath('data.lead.stage', 'qualified')
            ->assertJsonPath('data.lead.updated_by', $sales->id);
        $this->patchJson("/api/leads/{$salesLead->id}/stage", ['stage' => 'new'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_stage_transition_not_allowed');

        Sanctum::actingAs($administrator);
        $this->patchJson("/api/leads/{$adminLead->id}/stage", ['stage' => 'viewing'])
            ->assertOk();
        $this->patchJson("/api/leads/{$adminLead->id}/stage", ['stage' => 'qualified'])
            ->assertOk()
            ->assertJsonPath('data.lead.stage', 'qualified')
            ->assertJsonPath('data.lead.updated_by', $administrator->id);

        $this->assertSame(3, $this->activityCount(ActivityType::StageChange));
    }

    public function test_claim_assign_and_unassign_follow_role_and_activity_rules(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $sales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $projectManager = $this->createLeadUser(
            $tenant,
            User::ROLE_PROJECT_MANAGER,
        )['user'];
        $unassigned = $this->createLead($tenant, $administrator, ['assigned_to' => null]);
        Sanctum::actingAs($sales);

        $this->patchJson("/api/leads/{$unassigned->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.lead.assigned_to.id', $sales->id)
            ->assertJsonPath('data.lead.updated_by', $sales->id);
        $this->patchJson("/api/leads/{$unassigned->id}/claim")
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_claim_conflict');
        $this->patchJson("/api/leads/{$unassigned->id}/assign", ['assigned_to' => null])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'lead_action_not_authorized');

        Sanctum::actingAs($administrator);
        $this->patchJson("/api/leads/{$unassigned->id}/assign", ['assigned_to' => $projectManager->id])
            ->assertOk()
            ->assertJsonPath('data.lead.assigned_to.id', $projectManager->id)
            ->assertJsonPath('data.lead.updated_by', $administrator->id);
        $this->patchJson("/api/leads/{$unassigned->id}/assign", ['assigned_to' => null])
            ->assertOk()
            ->assertJsonPath('data.lead.assigned_to', null)
            ->assertJsonPath('data.lead.updated_by', $administrator->id);

        $this->assertSame(3, $this->activityCount(ActivityType::Assignment));
    }

    public function test_loss_and_reopen_are_atomic_and_clear_ineligible_assignment(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $salesContext = $this->createLeadUser($tenant, User::ROLE_SALES);
        $sales = $salesContext['user'];
        $lead = $this->createLead($tenant, $sales, [
            'next_follow_up_at' => now()->addDay(),
        ]);
        Sanctum::actingAs($sales);

        $this->patchJson("/api/leads/{$lead->id}/lose", [
            'lost_reason' => LostReason::Other->value,
            'lost_reason_detail' => 'Customer postponed the decision',
        ])
            ->assertOk()
            ->assertJsonPath('data.lead.stage', 'lost')
            ->assertJsonPath('data.lead.next_follow_up_at', null)
            ->assertJsonPath('data.lead.lost_reason', 'other')
            ->assertJsonPath('data.lead.updated_by', $sales->id);
        $this->patchJson("/api/leads/{$lead->id}/reopen")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'lead_action_not_authorized');

        $salesContext['membership']->update(['status' => TenantUser::STATUS_PAUSED]);
        Sanctum::actingAs($administrator);
        $this->patchJson("/api/leads/{$lead->id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.lead.stage', 'new')
            ->assertJsonPath('data.lead.assigned_to', null)
            ->assertJsonPath('data.lead.lost_reason', null)
            ->assertJsonPath('data.lead.updated_by', $administrator->id);

        $this->assertSame(2, $this->activityCount(ActivityType::StageChange));
        $this->assertSame(1, $this->activityCount(ActivityType::Assignment));
    }

    public function test_archive_and_restore_preserve_stage_and_follow_up_and_clear_ineligible_assignment(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $salesContext = $this->createLeadUser($tenant, User::ROLE_SALES);
        $followUp = CarbonImmutable::now('UTC')->addDays(2);
        $lead = $this->createLead($tenant, $salesContext['user'], [
            'stage' => LeadStage::Qualified,
            'next_follow_up_at' => $followUp,
        ]);
        Sanctum::actingAs($administrator);

        $this->patchJson("/api/leads/{$lead->id}/archive", [
            'archive_reason' => ArchiveReason::Other->value,
            'archive_reason_detail' => 'Duplicate sales opportunity',
        ])
            ->assertOk()
            ->assertJsonPath('data.lead.stage', 'qualified')
            ->assertJsonPath('data.lead.archive_reason', 'other')
            ->assertJsonPath('data.lead.updated_by', $administrator->id);

        $lead->refresh();
        $this->assertSame($followUp->toISOString(), $lead->next_follow_up_at?->toISOString());
        $salesContext['membership']->update(['status' => TenantUser::STATUS_PAUSED]);

        $this->patchJson("/api/leads/{$lead->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.lead.stage', 'qualified')
            ->assertJsonPath('data.lead.archived_at', null)
            ->assertJsonPath('data.lead.assigned_to', null)
            ->assertJsonPath('data.lead.updated_by', $administrator->id);

        $won = $this->createLead($tenant, $administrator, [
            'stage' => LeadStage::Won,
        ]);
        $this->patchJson("/api/leads/{$won->id}/archive", [
            'archive_reason' => ArchiveReason::Duplicate->value,
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_won_cannot_be_archived');

        $this->assertSame(1, $this->activityCount(ActivityType::Archive));
        $this->assertSame(1, $this->activityCount(ActivityType::Restore));
        $this->assertSame(1, $this->activityCount(ActivityType::Assignment));
    }

    public function test_pause_guard_is_tenant_scoped_and_blocks_only_open_active_assignments(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $projectManagerContext = $this->createLeadUser(
            $tenant,
            User::ROLE_PROJECT_MANAGER,
        );
        $this->createLead($tenant, $projectManagerContext['user']);
        Sanctum::actingAs($administrator);

        $this->putJson("/api/users/{$projectManagerContext['membership']->id}", [
            'status' => TenantUser::STATUS_PAUSED,
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'user_has_open_assigned_leads');

        $this->putJson("/api/users/{$projectManagerContext['membership']->id}", [
            'status' => TenantUser::STATUS_SUSPENDED,
        ])->assertOk()
            ->assertJsonPath('data.user.status', TenantUser::STATUS_SUSPENDED);
    }

    public function test_other_tenant_closed_and_archived_leads_do_not_block_pause(): void
    {
        foreach (['other_tenant', 'closed', 'archived'] as $case) {
            $tenant = $this->createLeadTenant();
            $administrator = $this->createLeadUser(
                $tenant,
                User::ROLE_ADMINISTRATOR,
            )['user'];
            $projectManagerContext = $this->createLeadUser(
                $tenant,
                User::ROLE_PROJECT_MANAGER,
            );

            if ($case === 'other_tenant') {
                $otherTenant = $this->createLeadTenant();
                $otherAdmin = $this->createLeadUser(
                    $otherTenant,
                    User::ROLE_ADMINISTRATOR,
                )['user'];
                TenantUser::factory()
                    ->forTenant($otherTenant)
                    ->forUser($projectManagerContext['user'])
                    ->active()
                    ->create();
                $this->createLead($otherTenant, $otherAdmin, [
                    'assigned_to' => $projectManagerContext['user']->id,
                ]);
            } elseif ($case === 'closed') {
                $this->createLead($tenant, $projectManagerContext['user'], ['stage' => 'lost']);
            } else {
                $this->createLead($tenant, $projectManagerContext['user'], [
                    'archived_at' => now(),
                ]);
            }

            Sanctum::actingAs($administrator);
            $this->putJson("/api/users/{$projectManagerContext['membership']->id}", [
                'status' => TenantUser::STATUS_PAUSED,
            ])->assertOk()
                ->assertJsonPath('data.user.status', TenantUser::STATUS_PAUSED);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function createPayload(string $phone): array
    {
        return [
            'name' => 'CRM API Lead',
            'phone' => $phone,
            'source' => LeadSource::WhatsApp->value,
        ];
    }

    private function activityCount(ActivityType $type): int
    {
        return (int) \App\Modules\Leads\Models\LeadActivity::query()
            ->where('type', $type->value)
            ->count();
    }

    private function freezeCrmClock(CarbonImmutable $time): void
    {
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
    }
}
