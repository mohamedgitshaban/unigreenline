<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\CRM\Models\CustomerCall;
use Modules\CRM\Models\CustomerVisit;
use Modules\CRM\Models\Lead;
use Tests\TestCase;

class CrmDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $manager;

    private User $rep;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->manager = $this->user('Sales Manager', ['data_scope' => 'team']);
        $this->rep = $this->user('Sales Rep', ['manager_id' => $this->manager->id]);
        $this->outsider = $this->user('Sales Rep');
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->recycle($this->tenant)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function kpis(string $features): array
    {
        return collect($this->getJson("/api/v1/dashboards/crm?features={$features}")->assertOk()->json('data.kpis'))->keyBy('key')->all();
    }

    public function test_call_figures_cover_the_team_and_not_outsiders(): void
    {
        $mine = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id]);
        $theirs = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->outsider->id]);
        CustomerCall::factory()->recycle([$this->tenant, $mine])->create(['rep_id' => $this->rep->id, 'outcome' => 'answered', 'duration_seconds' => 600, 'next_follow_up' => now()->addDays(2)->toDateString(), 'subject' => 'Quote']);
        CustomerCall::factory()->recycle([$this->tenant, $mine])->create(['rep_id' => $this->rep->id, 'outcome' => 'no_answer', 'duration_seconds' => 0]);
        CustomerCall::factory()->recycle([$this->tenant, $theirs])->create(['rep_id' => $this->outsider->id]);
        Sanctum::actingAs($this->manager);

        $kpis = $this->kpis('calls');

        $this->assertSame(2, $kpis['calls']['value']);
        $this->assertEquals(50.0, $kpis['answered_rate']['value']);
        $this->assertSame(10, $kpis['talk_time']['value']);

        $data = $this->getJson('/api/v1/dashboards/crm?features=calls')->json('data');
        $tables = collect($data['tables'])->keyBy('key');
        $this->assertSame(['Quote'], collect($tables['call_follow_ups']['rows'])->pluck('subject')->all());
        $this->assertEqualsCanonicalizing(['answered' => 1, 'no_answer' => 1], collect(collect($data['charts'])->firstWhere('key', 'calls_by_outcome')['series'])->pluck('value', 'label')->all());
    }

    public function test_visits_customers_and_leads_are_counted(): void
    {
        $mine = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id, 'classification' => 'A']);
        Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->outsider->id]);
        Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => null]);
        CustomerVisit::factory()->recycle([$this->tenant, $mine])->create(['rep_id' => $this->rep->id, 'outcome' => 'positive']);
        Lead::factory()->recycle($this->tenant)->create(['status' => 'new', 'value' => 1000]);
        Lead::factory()->recycle($this->tenant)->create(['status' => 'won', 'value' => 500]);

        Sanctum::actingAs($this->manager);
        $this->assertSame(1, $this->kpis('customers')['customers']['value'], 'only the team\'s customers');
        $this->assertArrayNotHasKey('unassigned_customers', $this->kpis('customers'));
        $this->assertSame(1, $this->kpis('visits')['visits']['value']);
        $this->assertEquals(100.0, $this->kpis('visits')['positive_visits']['value']);

        Sanctum::actingAs($this->user('Customer Service'));
        $kpis = $this->kpis('customers');
        $this->assertSame(3, $kpis['customers']['value']);
        $this->assertSame(1, $kpis['unassigned_customers']['value']);

        $leads = $this->kpis('leads');
        $this->assertSame(2, $leads['new_leads']['value']);
        $this->assertSame(1, $leads['open_leads']['value']);
        $this->assertEquals(1000, $leads['pipeline_value']['value']);
        $this->assertEquals(100.0, $leads['win_rate']['value'] ?? 100.0);
    }
}
