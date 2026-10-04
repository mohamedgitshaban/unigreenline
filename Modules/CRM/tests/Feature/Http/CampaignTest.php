<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Campaign;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_service_creates_a_campaign(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/campaigns', [
            'name' => 'Spring Vaccination Drive',
            'start_date' => now()->toDateString(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'active');
        $response->assertJsonPath('data.created_by.id', $user->id);
    }

    public function test_ending_a_campaign_marks_it_completed(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);
        $campaign = Campaign::factory()->recycle($tenant)->create(['status' => 'active']);

        $response = $this->putJson("/api/v1/campaigns/{$campaign->id}/end");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'completed');
    }
}
