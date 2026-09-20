<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Complaint;
use Modules\CRM\Models\Customer;
use Tests\TestCase;

class ComplaintTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_service_files_a_complaint(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);
        $customer = Customer::factory()->recycle($tenant)->create();

        $response = $this->postJson('/api/v1/complaints', [
            'customer_id' => $customer->id,
            'description' => 'Product arrived damaged.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'investigating');
    }

    public function test_resolving_a_complaint_requires_a_resolution_and_sets_resolved_date(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);
        $customer = Customer::factory()->recycle($tenant)->create();
        $complaint = Complaint::factory()->recycle([$tenant, $customer])->create();

        $response = $this->putJson("/api/v1/complaints/{$complaint->id}/resolve", [
            'resolution' => 'Replacement shipped.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'resolved');
        $this->assertNotNull($complaint->fresh()->resolved_date);
    }

    public function test_resolving_without_a_resolution_text_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);
        $customer = Customer::factory()->recycle($tenant)->create();
        $complaint = Complaint::factory()->recycle([$tenant, $customer])->create();

        $response = $this->putJson("/api/v1/complaints/{$complaint->id}/resolve", []);

        $response->assertUnprocessable();
    }
}
