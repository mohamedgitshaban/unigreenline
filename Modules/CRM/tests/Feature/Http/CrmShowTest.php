<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\Complaint;
use Modules\CRM\Models\CustomerVisit;
use Modules\CRM\Models\Lead;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CrmShowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function resources(): array
    {
        return [
            'leads' => ['leads', Lead::class],
            'visits' => ['visits', CustomerVisit::class],
            'complaints' => ['complaints', Complaint::class],
            'campaigns' => ['campaigns', Campaign::class],
        ];
    }

    #[DataProvider('resources')]
    public function test_customer_service_can_view_a_record(string $uri, string $model): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $record = $model::factory()->recycle($tenant)->create();

        $response = $this->getJson("/api/v1/{$uri}/{$record->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $record->id);
    }

    #[DataProvider('resources')]
    public function test_a_record_from_another_tenant_is_not_found(string $uri, string $model): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $record = $model::factory()->create();

        $response = $this->getJson("/api/v1/{$uri}/{$record->id}");

        $response->assertNotFound();
    }

    public function test_user_without_crm_access_cannot_view_a_lead(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Employee');
        Sanctum::actingAs($user);

        $lead = Lead::factory()->recycle($tenant)->create();

        $response = $this->getJson("/api/v1/leads/{$lead->id}");

        $response->assertForbidden();
    }
}
