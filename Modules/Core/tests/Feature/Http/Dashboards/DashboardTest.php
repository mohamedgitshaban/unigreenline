<?php

namespace Modules\Core\Tests\Feature\Http\Dashboards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Dashboards\DashboardRegistry;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->recycle($this->tenant)->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    public function test_every_department_has_a_dashboard_that_builds_on_an_empty_company(): void
    {
        Sanctum::actingAs($this->user('Administrator'));

        $listed = collect($this->getJson('/api/v1/dashboards')->assertOk()->json('data'));
        $registered = array_keys(app(DashboardRegistry::class)->all());

        $this->assertEqualsCanonicalizing($registered, $listed->pluck('module')->all());
        $this->assertEqualsCanonicalizing(['accounting', 'crm', 'expenses', 'hr', 'inventory', 'manufacturing', 'purchasing', 'recap', 'sales'], $registered);

        foreach ($registered as $module) {
            $response = $this->getJson("/api/v1/dashboards/{$module}");
            $this->assertSame(200, $response->status(), "the {$module} dashboard failed: ".($response->exception?->getMessage() ?? $response->getContent()));

            $response->assertJsonPath('data.module', $module)->assertJsonStructure(['data' => ['title', 'generated_at', 'period' => ['from', 'to', 'label', 'days', 'previous'], 'scope', 'features', 'kpis', 'charts', 'tables']]);
            $this->assertNotEmpty($response->json('data.kpis'), "{$module} has no headline numbers");
            $this->assertNotEmpty($response->json('data.features'));

            foreach ($response->json('data.kpis') as $kpi) {
                $this->assertArrayHasKey('feature', $kpi);
                $this->assertContains($kpi['unit'], ['count', 'currency', 'percent', 'cartons', 'days', 'hours']);
            }
        }
    }

    public function test_every_period_can_be_asked_for(): void
    {
        Sanctum::actingAs($this->user('Administrator'));

        foreach (['today', 'yesterday', '7d', '30d', 'month', 'last_month', 'quarter', 'year'] as $period) {
            $this->getJson("/api/v1/dashboards/sales?period={$period}")->assertOk()->assertJsonPath('data.period.label', $period);
        }

        $this->getJson('/api/v1/dashboards/sales?period=custom&from=2026-01-01&to=2026-01-31')
            ->assertOk()->assertJsonPath('data.period.from', '2026-01-01')->assertJsonPath('data.period.days', 31)->assertJsonPath('data.period.previous.to', '2025-12-31');
    }

    public function test_a_bad_period_is_refused(): void
    {
        Sanctum::actingAs($this->user('Administrator'));

        $this->getJson('/api/v1/dashboards/sales?period=forever')->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->getJson('/api/v1/dashboards/sales?period=custom')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getJson('/api/v1/dashboards/sales?period=custom&from=2026-02-01&to=2026-01-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_a_dashboard_can_be_narrowed_to_some_of_its_features(): void
    {
        Sanctum::actingAs($this->user('Administrator'));

        $response = $this->getJson('/api/v1/dashboards/sales?features=deliveries')->assertOk();

        $this->assertSame(['deliveries'], collect($response->json('data.kpis'))->pluck('feature')->unique()->values()->all());
        $this->assertCount(3, $response->json('data.features'), 'every feature is still listed');
    }

    public function test_a_user_sees_only_the_dashboards_their_role_allows(): void
    {
        Sanctum::actingAs($this->user('Warehouse Manager'));

        $modules = collect($this->getJson('/api/v1/dashboards')->assertOk()->json('data'))->pluck('module')->all();

        $this->assertContains('inventory', $modules);
        $this->assertNotContains('hr', $modules);
        $this->assertNotContains('accounting', $modules);
        $this->getJson('/api/v1/dashboards/hr')->assertForbidden();
        $this->getJson('/api/v1/dashboards/accounting')->assertForbidden();
        $this->getJson('/api/v1/dashboards/inventory')->assertOk();
    }

    public function test_features_the_user_may_not_open_are_marked_unavailable_and_left_out(): void
    {
        Sanctum::actingAs($this->user('Accountant'));

        $response = $this->getJson('/api/v1/dashboards/accounting')->assertOk();
        $features = collect($response->json('data.features'))->keyBy('key');

        $this->assertTrue($features['ledger']['available']);
        $this->assertTrue($features['balances']['available']);
    }

    public function test_a_dashboard_nobody_registered_is_not_found(): void
    {
        $admin = $this->user('Administrator');
        Sanctum::actingAs($admin);

        $this->assertNull(app(DashboardRegistry::class)->find('astronomy'));
        $this->getJson('/api/v1/dashboards/astronomy')->assertNotFound();
    }

    public function test_dashboards_need_a_signed_in_user(): void
    {
        $this->getJson('/api/v1/dashboards')->assertUnauthorized();
        $this->getJson('/api/v1/dashboards/sales')->assertUnauthorized();
    }
}
