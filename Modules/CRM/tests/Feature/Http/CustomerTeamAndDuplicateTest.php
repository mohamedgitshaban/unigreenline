<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\CRM\Models\CustomerVisit;
use Tests\TestCase;

class CustomerTeamAndDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    private function user(string $role, ?User $manager = null, array $attributes = []): User
    {
        $user = User::factory()->recycle($this->tenant)->create(['manager_id' => $manager?->id, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    private function customer(?User $owner = null, array $attributes = []): Customer
    {
        return Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $owner?->id, ...$attributes]);
    }

    private function payload(array $overrides = []): array
    {
        return ['name' => 'Green Valley Vets', 'type' => 'Clinic', 'phone' => '0100 123 4567', 'email' => 'gv@example.com', 'city' => 'Giza', ...$overrides];
    }

    public function test_a_manager_sees_the_customers_of_their_whole_team_but_not_outsiders(): void
    {
        $manager = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $lead = $this->user('Sales Rep', $manager);
        $rep = $this->user('Sales Rep', $lead);
        $outsider = $this->user('Sales Rep');

        $mine = $this->customer($manager);
        $leads = $this->customer($lead);
        $reps = $this->customer($rep);
        $theirs = $this->customer($outsider);
        $nobodys = $this->customer();

        Sanctum::actingAs($manager);
        $ids = collect($this->getJson('/api/v1/customers')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$mine->id, $leads->id, $reps->id], $ids);
        $this->getJson("/api/v1/customers/{$reps->id}")->assertOk();
        $this->getJson("/api/v1/customers/{$theirs->id}")->assertForbidden();
        $this->getJson("/api/v1/customers/{$nobodys->id}")->assertForbidden();
        $this->putJson("/api/v1/customers/{$reps->id}", ['name' => 'Renamed'])->assertOk();
        $this->putJson("/api/v1/customers/{$theirs->id}", ['name' => 'Hijacked'])->assertForbidden();
    }

    public function test_a_rep_with_nobody_below_still_sees_only_their_own(): void
    {
        $boss = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $rep = $this->user('Sales Rep', $boss);
        $mine = $this->customer($rep);
        $this->customer($boss);
        Sanctum::actingAs($rep);

        $this->assertSame([$mine->id], collect($this->getJson('/api/v1/customers')->json('data'))->pluck('id')->all());
    }

    public function test_a_manager_without_a_team_scope_sees_every_customer(): void
    {
        $manager = $this->user('Sales Manager');
        $this->customer($this->user('Sales Rep'));
        $this->customer();
        Sanctum::actingAs($manager);

        $this->assertCount(2, $this->getJson('/api/v1/customers')->json('data'));
    }

    public function test_a_new_customer_is_created_under_the_creators_own_name_when_team_scoped(): void
    {
        $rep = $this->user('Sales Rep');
        Sanctum::actingAs($rep);

        $this->postJson('/api/v1/customers', $this->payload())->assertCreated()->assertJsonPath('data.sales_rep.id', $rep->id);
    }

    public function test_a_manager_creates_a_customer_for_a_report_but_not_for_an_outsider(): void
    {
        $manager = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $report = $this->user('Sales Rep', $manager);
        $outsider = $this->user('Sales Rep');
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/customers', $this->payload(['sales_rep_id' => $report->id]))->assertCreated()->assertJsonPath('data.sales_rep.id', $report->id);
        $this->postJson('/api/v1/customers', $this->payload(['name' => 'Another', 'phone' => '0111', 'email' => 'x@example.com', 'sales_rep_id' => $outsider->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('sales_rep_id');
    }

    public function test_a_manager_assigns_a_customer_to_a_report(): void
    {
        $manager = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $report = $this->user('Sales Rep', $manager);
        $customer = $this->customer($manager);
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/customers/{$customer->id}/assign", ['sales_rep_id' => $report->id])
            ->assertOk()->assertJsonPath('data.sales_rep.id', $report->id);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'sales_rep_id' => $report->id]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Customer', 'entity_id' => $customer->id, 'operation' => 'UPDATE', 'user_id' => $manager->id]);
    }

    public function test_a_customer_cannot_be_assigned_outside_the_assigners_team(): void
    {
        $manager = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $report = $this->user('Sales Rep', $manager);
        $outsider = $this->user('Sales Rep');
        $customer = $this->customer($report);
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/customers/{$customer->id}/assign", ['sales_rep_id' => $outsider->id])
            ->assertUnprocessable()->assertJsonValidationErrors('sales_rep_id');
        $this->postJson("/api/v1/customers/{$customer->id}/assign", ['sales_rep_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('sales_rep_id');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'sales_rep_id' => $report->id]);
    }

    public function test_a_customer_outside_the_assigners_reach_cannot_be_assigned(): void
    {
        $manager = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $outsider = $this->user('Sales Rep');
        $customer = $this->customer($outsider);
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/customers/{$customer->id}/assign", ['sales_rep_id' => $manager->id])->assertForbidden();
    }

    public function test_someone_who_sees_everything_can_assign_to_anyone_or_unassign(): void
    {
        $manager = $this->user('Sales Manager');
        $rep = $this->user('Sales Rep');
        $customer = $this->customer();
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/customers/{$customer->id}/assign", ['sales_rep_id' => $rep->id])->assertOk()->assertJsonPath('data.sales_rep.id', $rep->id);
        $this->postJson("/api/v1/customers/{$customer->id}/assign", ['sales_rep_id' => null])->assertOk()->assertJsonPath('data.sales_rep', null);
        $this->postJson("/api/v1/customers/{$customer->id}/assign", [])->assertUnprocessable();
    }

    public function test_assigning_needs_a_user_of_the_same_company_and_the_customer_to_be_the_companys(): void
    {
        $manager = $this->user('Sales Manager');
        $customer = $this->customer();
        $foreignCustomer = Customer::factory()->create();
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/customers/{$customer->id}/assign", ['sales_rep_id' => User::factory()->create()->id])->assertUnprocessable();
        $this->postJson("/api/v1/customers/{$foreignCustomer->id}/assign", ['sales_rep_id' => $manager->id])->assertNotFound();
    }

    public function test_creating_a_customer_that_belongs_to_another_user_is_refused_and_says_whose_it_is(): void
    {
        $owner = $this->user('Sales Rep');
        $rep = $this->user('Sales Rep');
        $existing = $this->customer($owner, ['name' => 'Green Valley Vets', 'phone' => '+20 100-123-4567', 'email' => 'other@example.com', 'city' => 'Cairo']);
        Sanctum::actingAs($rep);

        $this->postJson('/api/v1/customers', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('duplicates.0.name', 'Green Valley Vets')
            ->assertJsonPath('duplicates.0.matched_on', ['phone'])
            ->assertJsonPath('duplicates.0.sales_rep.id', $owner->id)
            ->assertJsonPath('duplicates.0.sales_rep.name', $owner->name)
            ->assertJsonPath('duplicates.0.id', null)
            ->assertJsonPath('duplicates.0.owned_by_me', false)
            ->assertJsonPath('can_override', false);

        $this->postJson('/api/v1/customers', $this->payload(['allow_duplicate' => true]))->assertUnprocessable()->assertJsonPath('can_override', false);
        $this->assertSame(1, Customer::query()->where('tenant_id', $this->tenant->id)->count());
        $this->assertNotNull($existing);
    }

    public function test_a_match_on_email_or_on_name_and_city_is_also_a_duplicate(): void
    {
        $owner = $this->user('Sales Rep');
        $rep = $this->user('Sales Rep');
        $this->customer($owner, ['name' => 'Nile Farm', 'phone' => '111111', 'email' => 'Farm@Example.com', 'city' => 'Aswan']);
        Sanctum::actingAs($rep);

        $this->postJson('/api/v1/customers', $this->payload(['name' => 'Different', 'phone' => '222222', 'email' => 'farm@example.com', 'city' => 'Luxor']))
            ->assertUnprocessable()->assertJsonPath('duplicates.0.matched_on', ['email']);
        $this->postJson('/api/v1/customers', $this->payload(['name' => 'nile farm', 'phone' => '333333', 'email' => 'new@example.com', 'city' => 'ASWAN']))
            ->assertUnprocessable()->assertJsonPath('duplicates.0.matched_on', ['name_and_city']);
        $this->postJson('/api/v1/customers', $this->payload(['name' => 'Nile Farm', 'phone' => '444444', 'email' => 'new@example.com', 'city' => 'Luxor']))->assertCreated();
    }

    public function test_a_different_customer_is_created_normally(): void
    {
        $this->customer($this->user('Sales Rep'), ['phone' => '999999', 'email' => 'a@example.com']);
        Sanctum::actingAs($this->user('Sales Rep'));

        $this->postJson('/api/v1/customers', $this->payload())->assertCreated();
    }

    public function test_someone_who_sees_everything_may_create_a_duplicate_on_purpose(): void
    {
        $owner = $this->user('Sales Rep');
        $manager = $this->user('Sales Manager');
        $this->customer($owner, ['phone' => '01001234567']);
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/customers', $this->payload())->assertUnprocessable()->assertJsonPath('can_override', true);
        $this->postJson('/api/v1/customers', $this->payload(['allow_duplicate' => true]))->assertCreated();
    }

    public function test_a_duplicate_on_a_team_members_customer_can_be_overridden_by_their_manager(): void
    {
        $manager = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $report = $this->user('Sales Rep', $manager);
        $this->customer($report, ['phone' => '01001234567']);
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/customers', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('can_override', true)
            ->assertJsonPath('duplicates.0.visible_to_me', true);
        $this->postJson('/api/v1/customers', $this->payload(['allow_duplicate' => true]))->assertCreated();
    }

    public function test_the_duplicate_check_endpoint_reports_matches_without_creating_anything(): void
    {
        $owner = $this->user('Sales Rep');
        $rep = $this->user('Sales Rep');
        $this->customer($owner, ['phone' => '01001234567']);
        Sanctum::actingAs($rep);

        $this->getJson('/api/v1/customers/check-duplicate?phone=0100-123-4567')
            ->assertOk()
            ->assertJsonPath('data.has_duplicates', true)
            ->assertJsonPath('data.can_override', false)
            ->assertJsonPath('data.duplicates.0.sales_rep.id', $owner->id);

        $this->getJson('/api/v1/customers/check-duplicate?phone=555')->assertOk()->assertJsonPath('data.has_duplicates', false);
        $this->getJson('/api/v1/customers/check-duplicate')->assertOk()->assertJsonPath('data.has_duplicates', false);
        $this->assertSame(1, Customer::query()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_duplicates_in_other_companies_are_ignored(): void
    {
        Customer::factory()->create(['phone' => '01001234567', 'email' => 'gv@example.com']);
        Sanctum::actingAs($this->user('Sales Rep'));

        $this->postJson('/api/v1/customers', $this->payload())->assertCreated();
    }

    public function test_visits_follow_the_same_reporting_line(): void
    {
        $manager = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $rep = $this->user('Sales Rep', $manager);
        $outsider = $this->user('Sales Rep');
        $reps = CustomerVisit::factory()->recycle($this->tenant)->create(['rep_id' => $rep->id, 'customer_id' => $this->customer($rep)->id]);
        $others = CustomerVisit::factory()->recycle($this->tenant)->create(['rep_id' => $outsider->id, 'customer_id' => $this->customer($outsider)->id]);

        Sanctum::actingAs($manager);
        $this->assertSame([$reps->id], collect($this->getJson('/api/v1/visits')->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/visits/{$reps->id}")->assertOk();
        $this->getJson("/api/v1/visits/{$others->id}")->assertForbidden();

        Sanctum::actingAs($rep);
        $this->assertSame([$reps->id], collect($this->getJson('/api/v1/visits')->json('data'))->pluck('id')->all());
    }

    public function test_a_visit_cannot_be_logged_on_behalf_of_someone_outside_the_team(): void
    {
        $manager = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $report = $this->user('Sales Rep', $manager);
        $outsider = $this->user('Sales Rep');
        $customer = $this->customer($report);
        Sanctum::actingAs($manager);

        $body = ['customer_id' => $customer->id, 'visit_date' => now()->toDateString()];
        $this->postJson('/api/v1/visits', [...$body, 'rep_id' => $report->id])->assertCreated();
        $this->postJson('/api/v1/visits', [...$body, 'rep_id' => $outsider->id])->assertUnprocessable()->assertJsonValidationErrors('rep_id');
    }
}
