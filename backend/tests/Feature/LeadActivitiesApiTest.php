<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Leads\Enums\ActivityType;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Models\LeadActivity;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesLeadFixtures;

final class LeadActivitiesApiTest extends ApiTestCase
{
    use CreatesLeadFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-02 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_activity_timeline_is_visible_scoped_ordered_and_paginated(): void
    {
        $tenant = $this->createLeadTenant();
        $sales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $otherSales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $lead = $this->createLead($tenant, $sales);
        $hiddenLead = $this->createLead($tenant, $otherSales);

        $oldest = $this->createLeadActivity($lead, $sales, attributes: [
            'body' => 'Oldest note',
            'occurred_at' => now()->subHours(2),
        ]);
        $middle = $this->createLeadActivity($lead, $sales, attributes: [
            'body' => 'Middle note',
            'occurred_at' => now()->subHour(),
        ]);
        $newest = $this->createLeadActivity($lead, $sales, attributes: [
            'body' => 'Newest note',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($sales);
        $response = $this->getJson("/api/leads/{$lead->id}/activities?per_page=2");
        $response
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.activities.0.id', (string) $newest->id)
            ->assertJsonPath('data.activities.1.id', (string) $middle->id);
        $this->assertNotSame((string) $oldest->id, $response->json('data.activities.0.id'));

        $this->getJson("/api/leads/{$hiddenLead->id}/activities")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'lead_not_found');
    }

    public function test_sales_can_add_trimmed_note_to_own_open_lead_and_touch_audit_fields(): void
    {
        $tenant = $this->createLeadTenant();
        $sales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $lead = $this->createLead($tenant, $sales);
        Sanctum::actingAs($sales);
        Carbon::setTestNow('2026-08-02 13:30:00 UTC');

        $response = $this->postJson("/api/leads/{$lead->id}/notes", [
            'body' => '  Customer requested a second viewing.  ',
        ]);
        $response
            ->assertCreated()
            ->assertJsonPath('data.activity.type', ActivityType::Note->value)
            ->assertJsonPath('data.activity.body', 'Customer requested a second viewing.')
            ->assertJsonPath('data.activity.payload', null)
            ->assertJsonPath('data.activity.created_by.id', $sales->id);

        $lead->refresh();
        $this->assertSame($sales->id, $lead->updated_by);
        $this->assertSame('2026-08-02T13:30:00.000000Z', $lead->updated_at?->toISOString());
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'type' => ActivityType::Note->value,
            'body' => 'Customer requested a second viewing.',
            'created_by' => $sales->id,
        ]);
    }

    public function test_administrator_can_note_any_open_tenant_lead(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $sales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $assigned = $this->createLead($tenant, $sales);
        $unassigned = $this->createLead($tenant, $administrator, ['assigned_to' => null]);
        Sanctum::actingAs($administrator);

        foreach ([$assigned, $unassigned] as $lead) {
            $this->postJson("/api/leads/{$lead->id}/notes", [
                'body' => 'Administrator note',
            ])->assertCreated();
        }

        $this->assertSame(2, LeadActivity::query()
            ->where('type', ActivityType::Note->value)
            ->count());
    }

    public function test_unassigned_open_lead_is_read_only_for_sales_until_claimed(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $sales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $lead = $this->createLead($tenant, $administrator, ['assigned_to' => null]);
        Sanctum::actingAs($sales);

        $this->postJson("/api/leads/{$lead->id}/notes", ['body' => 'Denied'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'lead_action_not_authorized');

        $this->patchJson("/api/leads/{$lead->id}/claim")->assertOk();
        $this->postJson("/api/leads/{$lead->id}/notes", ['body' => 'Allowed'])
            ->assertCreated();
    }

    public function test_notes_are_blocked_for_lost_won_and_archived_leads(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $lost = $this->createLead($tenant, $administrator, ['stage' => LeadStage::Lost]);
        $won = $this->createLead($tenant, $administrator, ['stage' => LeadStage::Won]);
        $archived = $this->createLead($tenant, $administrator, ['archived_at' => now()]);
        Sanctum::actingAs($administrator);

        foreach ([$lost, $won] as $closed) {
            $this->postJson("/api/leads/{$closed->id}/notes", ['body' => 'Denied'])
                ->assertConflict()
                ->assertJsonPath('error.code', 'lead_not_in_open_stage');
        }

        $this->postJson("/api/leads/{$archived->id}/notes", ['body' => 'Denied'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_is_archived');
    }

    public function test_automatic_activities_are_created_with_frozen_payload_shape(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $sales = $this->createLeadUser($tenant, User::ROLE_SALES)['user'];
        $lead = $this->createLead($tenant, $sales);
        Sanctum::actingAs($sales);

        $this->patchJson("/api/leads/{$lead->id}/stage", ['stage' => 'qualified'])
            ->assertOk();
        Carbon::setTestNow('2026-08-02 12:01:00 UTC');
        $this->patchJson("/api/leads/{$lead->id}/lose", [
            'lost_reason' => 'not_ready',
        ])->assertOk();

        $activities = LeadActivity::query()
            ->where('lead_id', $lead->id)
            ->where('type', ActivityType::StageChange->value)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $activities);
        $this->assertSame(1, $activities[0]->payload['schema_version']);
        $this->assertSame('new', $activities[0]->payload['from_stage']);
        $this->assertSame('qualified', $activities[0]->payload['to_stage']);
        $this->assertSame('qualified', $activities[1]->payload['from_stage']);
        $this->assertSame('lost', $activities[1]->payload['to_stage']);
        $this->assertSame('not_ready', $activities[1]->payload['lost_reason']);
    }

    public function test_archived_activity_timeline_requires_administrator_archived_mode(): void
    {
        $tenant = $this->createLeadTenant();
        $administrator = $this->createLeadUser(
            $tenant,
            User::ROLE_ADMINISTRATOR,
        )['user'];
        $lead = $this->createLead($tenant, $administrator);
        $this->createLeadActivity($lead, $administrator);
        $lead->forceFill([
            'archived_at' => now(),
            'archived_by' => $administrator->id,
            'archive_reason' => 'duplicate',
        ])->save();
        Sanctum::actingAs($administrator);

        $this->getJson("/api/leads/{$lead->id}/activities")
            ->assertNotFound();
        $this->getJson("/api/leads/{$lead->id}/activities?archived=true")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }
}
