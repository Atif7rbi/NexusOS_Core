<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Customers\Enums\CustomerCategory;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\CustomerType;
use App\Modules\Customers\Models\Customer;
use App\Modules\Leads\Enums\ConversionMode;
use App\Modules\Leads\Enums\LeadStage;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesLeadFixtures;

final class LeadsConversionApiTest extends ApiTestCase
{
    use CreatesLeadFixtures;

    private function convertUrl(Lead $lead): string
    {
        return "/api/leads/{$lead->id}/convert";
    }

    private function createNewPayload(string $type = 'individual', string $category = 'buyer'): array
    {
        return [
            'conversion_intent' => 'create_new',
            'type'              => $type,
            'category'          => $category,
        ];
    }

    private function linkPayload(string $customerId): array
    {
        return [
            'conversion_intent'    => 'link_existing',
            'existing_customer_id' => $customerId,
        ];
    }

    // =========================================================================
    // Auth
    // =========================================================================

    public function test_guest_cannot_convert(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin);

        $this->postJson($this->convertUrl($lead), $this->createNewPayload())
            ->assertUnauthorized();
    }

    // =========================================================================
    // Authorization
    // =========================================================================

    public function test_administrator_can_convert_any_open_lead(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->createNewPayload())
            ->assertOk();
    }

    public function test_sales_can_convert_own_assigned_lead(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin]  = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        ['user' => $sales] = $this->createLeadUser($tenant, User::ROLE_SALES);
        $lead = $this->createLead($tenant, $admin, ['assigned_to' => $sales->id]);

        Sanctum::actingAs($sales);
        $this->postJson($this->convertUrl($lead), $this->createNewPayload())
            ->assertOk();
    }

    public function test_sales_cannot_convert_lead_assigned_to_someone_else(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin]  = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        ['user' => $sales1] = $this->createLeadUser($tenant, User::ROLE_SALES);
        ['user' => $sales2] = $this->createLeadUser($tenant, User::ROLE_SALES);
        $lead = $this->createLead($tenant, $admin, ['assigned_to' => $sales1->id]);

        Sanctum::actingAs($sales2);
        $this->postJson($this->convertUrl($lead), $this->createNewPayload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'lead_action_not_authorized');
    }

    public function test_sales_cannot_convert_unassigned_lead(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        ['user' => $sales] = $this->createLeadUser($tenant, User::ROLE_SALES);
        $lead = $this->createLead($tenant, $admin, ['assigned_to' => null]);

        Sanctum::actingAs($sales);
        $this->postJson($this->convertUrl($lead), $this->createNewPayload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'lead_action_not_authorized');
    }

    public function test_cross_tenant_lead_returns_404(): void
    {
        $tenantA = $this->createLeadTenant();
        $tenantB = $this->createLeadTenant();
        ['user' => $adminA] = $this->createLeadUser($tenantA, User::ROLE_ADMINISTRATOR);
        ['user' => $adminB] = $this->createLeadUser($tenantB, User::ROLE_ADMINISTRATOR);
        $leadA = $this->createLead($tenantA, $adminA);

        Sanctum::actingAs($adminB);
        $this->postJson($this->convertUrl($leadA), $this->createNewPayload())
            ->assertNotFound();
    }

    // =========================================================================
    // Create New Customer — intent A
    // =========================================================================

    public function test_create_new_creates_customer_and_marks_lead_won(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin);

        Sanctum::actingAs($admin);
        $response = $this->postJson($this->convertUrl($lead), $this->createNewPayload());

        $response->assertOk()
            ->assertJsonPath('data.lead.stage', LeadStage::Won->value)
            ->assertJsonPath('message', 'تم تحويل العميل المحتمل إلى عميل بنجاح.');

        $lead->refresh();
        $this->assertSame(LeadStage::Won, $lead->stage);
        $this->assertNotNull($lead->customer_id);
        $this->assertNotNull($lead->converted_at);
        $this->assertSame($admin->id, $lead->converted_by);
        $this->assertSame(ConversionMode::Created, $lead->conversion_mode);
        $this->assertNull($lead->next_follow_up_at);

        $customer = Customer::findOrFail($lead->customer_id);
        $this->assertSame($lead->name, $customer->name);
        $this->assertSame($lead->phone, $customer->phone);
        $this->assertSame(CustomerStatus::Customer, $customer->status);
        $this->assertSame(CustomerType::Individual, $customer->type);
        $this->assertSame(CustomerCategory::Buyer, $customer->category);
    }

    public function test_create_new_copies_email_from_lead(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin, ['email' => 'lead@example.com']);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->createNewPayload())->assertOk();

        $customer = Customer::where('tenant_id', $tenant->id)->where('phone', $lead->phone)->firstOrFail();
        $this->assertSame('lead@example.com', $customer->email);
    }

    public function test_create_new_records_stage_change_activity(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin, ['stage' => LeadStage::Negotiation]);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->createNewPayload())->assertOk();

        $activity = LeadActivity::where('lead_id', $lead->id)
            ->where('type', 'stage_change')
            ->latest('occurred_at')
            ->firstOrFail();

        $payload = $activity->payload;
        $this->assertSame('negotiation', $payload['from_stage']);
        $this->assertSame('won', $payload['to_stage']);
        $this->assertSame(ConversionMode::Created->value, $payload['conversion_mode']);
        $this->assertNull($payload['previous_customer_status']);
        $this->assertSame('customer', $payload['new_customer_status']);
        $this->assertNotNull($payload['customer_id']);
    }

    public function test_create_new_clears_next_follow_up_at(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin, [
            'next_follow_up_at' => now()->addWeek(),
        ]);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->createNewPayload())->assertOk();

        $this->assertNull($lead->fresh()->next_follow_up_at);
    }

    // =========================================================================
    // Link Existing — Customer status: customer
    // =========================================================================

    public function test_link_existing_customer_status_links_without_modification(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead     = $this->createLead($tenant, $admin);
        $customer = $this->createLeadCustomer($tenant, $admin, [
            'phone'  => $lead->phone,
            'status' => CustomerStatus::Customer->value,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->linkPayload($customer->id))->assertOk();

        $lead->refresh();
        $this->assertSame($customer->id, $lead->customer_id);
        $this->assertSame(ConversionMode::LinkedExisting, $lead->conversion_mode);

        $customer->refresh();
        $this->assertSame(CustomerStatus::Customer, $customer->status);

        $activity = LeadActivity::where('lead_id', $lead->id)
            ->where('type', 'stage_change')->latest('occurred_at')->firstOrFail();
        $this->assertSame(ConversionMode::LinkedExisting->value, $activity->payload['conversion_mode']);
        $this->assertSame('customer', $activity->payload['previous_customer_status']);
        $this->assertSame('customer', $activity->payload['new_customer_status']);
    }

    // =========================================================================
    // Link Existing — Customer status: inactive
    // =========================================================================

    public function test_link_existing_inactive_links_without_activating(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead     = $this->createLead($tenant, $admin);
        $customer = $this->createLeadCustomer($tenant, $admin, [
            'phone'  => $lead->phone,
            'status' => CustomerStatus::Inactive->value,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->linkPayload($customer->id))->assertOk();

        $customer->refresh();
        $this->assertSame(CustomerStatus::Inactive, $customer->status);

        $lead->refresh();
        $this->assertSame(ConversionMode::LinkedExisting, $lead->conversion_mode);

        $activity = LeadActivity::where('lead_id', $lead->id)
            ->where('type', 'stage_change')->latest('occurred_at')->firstOrFail();
        $this->assertSame('inactive', $activity->payload['previous_customer_status']);
        $this->assertSame('inactive', $activity->payload['new_customer_status']);
    }

    // =========================================================================
    // Link Existing — Customer status: lead (legacy → promote)
    // =========================================================================

    public function test_link_legacy_lead_customer_promotes_to_customer(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead     = $this->createLead($tenant, $admin);
        $customer = $this->createLeadCustomer($tenant, $admin, [
            'phone'  => $lead->phone,
            'status' => CustomerStatus::Lead->value,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->linkPayload($customer->id))->assertOk();

        $customer->refresh();
        $this->assertSame(CustomerStatus::Customer, $customer->status);

        $lead->refresh();
        $this->assertSame(ConversionMode::LinkedAndPromoted, $lead->conversion_mode);

        $activity = LeadActivity::where('lead_id', $lead->id)
            ->where('type', 'stage_change')->latest('occurred_at')->firstOrFail();
        $this->assertSame(ConversionMode::LinkedAndPromoted->value, $activity->payload['conversion_mode']);
        $this->assertSame('lead', $activity->payload['previous_customer_status']);
        $this->assertSame('customer', $activity->payload['new_customer_status']);
    }

    // =========================================================================
    // Link Existing — Customer status: archived → blocked
    // =========================================================================

    public function test_link_archived_customer_is_rejected(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead     = $this->createLead($tenant, $admin);
        $customer = $this->createLeadCustomer($tenant, $admin, [
            'phone'      => $lead->phone,
            'status'     => CustomerStatus::Archived->value,
            'archived_at'=> now(),
            'archived_by'=> $admin->id,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->linkPayload($customer->id))
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_conversion_customer_archived');

        $this->assertSame(LeadStage::New, $lead->fresh()->stage);
    }

    // =========================================================================
    // Lead lifecycle guards
    // =========================================================================

    public function test_cannot_convert_already_won_lead(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin, ['stage' => LeadStage::Won]);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->createNewPayload())
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_already_converted');
    }

    public function test_cannot_convert_lost_lead(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin, ['stage' => LeadStage::Lost]);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->createNewPayload())
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_not_in_open_stage');
    }

    public function test_cannot_convert_archived_lead(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin, [
            'archived_at'    => now(),
            'archive_reason' => 'duplicate',
        ]);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->createNewPayload())
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_is_archived');
    }

    // =========================================================================
    // Conversion from all open stages
    // =========================================================================

    /** @dataProvider openStagesProvider */
    public function test_conversion_allowed_from_all_open_stages(LeadStage $stage): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin, ['stage' => $stage]);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->createNewPayload())
            ->assertOk()
            ->assertJsonPath('data.lead.stage', LeadStage::Won->value);
    }

    public static function openStagesProvider(): array
    {
        return [
            'new'         => [LeadStage::New],
            'qualified'   => [LeadStage::Qualified],
            'viewing'     => [LeadStage::Viewing],
            'negotiation' => [LeadStage::Negotiation],
        ];
    }

    // =========================================================================
    // Race condition: create_new conflicts mid-transaction
    // =========================================================================

    public function test_create_new_surfaces_conflict_when_customer_appears_in_transaction(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin);

        // Simulate a Customer being created with the same phone between preview and commit
        $this->createLeadCustomer($tenant, $admin, [
            'phone'  => $lead->phone,
            'status' => CustomerStatus::Customer->value,
        ]);

        Sanctum::actingAs($admin);
        $response = $this->postJson($this->convertUrl($lead), $this->createNewPayload());

        $response->assertConflict()
            ->assertJsonPath('error.code', 'lead_conversion_new_customer_conflict');

        $this->assertArrayHasKey('conflicting_customer', $response->json('error'));
        $this->assertSame(LeadStage::New, $lead->fresh()->stage);
    }

    // =========================================================================
    // Race condition: customer state changes between link preview and commit
    // =========================================================================

    public function test_link_surfaces_conflict_when_customer_phone_changes(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead     = $this->createLead($tenant, $admin);
        $customer = $this->createLeadCustomer($tenant, $admin, [
            'phone'  => $lead->phone,
            'status' => CustomerStatus::Customer->value,
        ]);

        // Change the customer's phone between preview and commit
        $customer->forceFill(['phone' => $this->nextLeadPhone()])->save();

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->linkPayload($customer->id))
            ->assertConflict()
            ->assertJsonPath('error.code', 'lead_conversion_customer_changed');

        $this->assertSame(LeadStage::New, $lead->fresh()->stage);
    }

    // =========================================================================
    // Atomicity — rollback on failure
    // =========================================================================

    public function test_conversion_is_fully_rolled_back_on_db_error(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin);

        // Create a Customer with the same phone to force a uniqueness violation
        // when create_new is attempted without acknowledge
        $this->createLeadCustomer($tenant, $admin, ['phone' => $lead->phone]);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), $this->createNewPayload())
            ->assertConflict();

        // Lead must not be in won state
        $this->assertSame(LeadStage::New, $lead->fresh()->stage);
        $this->assertNull($lead->fresh()->customer_id);

        // No spurious Activity created
        $this->assertDatabaseMissing('lead_activities', [
            'lead_id' => $lead->id,
            'type'    => 'stage_change',
            'payload->to_stage' => LeadStage::Won->value,
        ]);
    }

    // =========================================================================
    // Request validation
    // =========================================================================

    public function test_missing_conversion_intent_returns_422(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), [])
            ->assertUnprocessable();
    }

    public function test_create_new_without_type_returns_422(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), [
            'conversion_intent' => 'create_new',
            'category'          => 'buyer',
        ])->assertUnprocessable();
    }

    public function test_link_existing_without_customer_id_returns_422(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead = $this->createLead($tenant, $admin);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), [
            'conversion_intent' => 'link_existing',
        ])->assertUnprocessable();
    }

    public function test_create_new_rejects_existing_customer_id_field(): void
    {
        $tenant = $this->createLeadTenant();
        ['user' => $admin] = $this->createLeadUser($tenant, User::ROLE_ADMINISTRATOR);
        $lead     = $this->createLead($tenant, $admin);
        $customer = $this->createLeadCustomer($tenant, $admin, ['phone' => $this->nextLeadPhone()]);

        Sanctum::actingAs($admin);
        $this->postJson($this->convertUrl($lead), [
            'conversion_intent'    => 'create_new',
            'type'                 => 'individual',
            'category'             => 'buyer',
            'existing_customer_id' => $customer->id,
        ])->assertUnprocessable();
    }
}
