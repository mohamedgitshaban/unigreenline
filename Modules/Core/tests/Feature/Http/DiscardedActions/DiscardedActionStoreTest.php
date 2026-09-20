<?php

namespace Modules\Core\Tests\Feature\Http\DiscardedActions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class DiscardedActionStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_any_authenticated_user_can_save_a_discarded_action(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/discarded-actions', [
            'type' => 'sales_order',
            'label' => 'New sales order for Al-Salam Vet Clinic',
            'payload' => ['customer_id' => 'cust-1', 'lines' => []],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('discarded_actions', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'type' => 'sales_order',
        ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/v1/discarded-actions', [
            'type' => 'sales_order',
            'label' => 'Draft',
            'payload' => [],
        ]);

        $response->assertUnauthorized();
    }

    public function test_missing_required_fields_returns_422(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/discarded-actions', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['type', 'label', 'payload']);
    }
}
