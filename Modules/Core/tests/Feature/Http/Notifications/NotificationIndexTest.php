<?php

namespace Modules\Core\Tests\Feature\Http\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class NotificationIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/notifications');

        $response->assertUnauthorized();
    }

    public function test_sees_own_and_broadcast_notifications_but_not_another_users(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $other = User::factory()->recycle($tenant)->create();
        Sanctum::actingAs($user);

        $own = Notification::factory()->recycle($tenant)->create(['user_id' => $user->id, 'title' => 'Own']);
        $broadcast = Notification::factory()->recycle($tenant)->create(['user_id' => null, 'title' => 'Broadcast']);
        $others = Notification::factory()->recycle($tenant)->create(['user_id' => $other->id, 'title' => 'Not mine']);

        $response = $this->getJson('/api/v1/notifications');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($own->id));
        $this->assertTrue($ids->contains($broadcast->id));
        $this->assertFalse($ids->contains($others->id));
    }

    public function test_scoped_to_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        Sanctum::actingAs($user);

        Notification::factory()->recycle($otherTenant)->create(['user_id' => null]);

        $response = $this->getJson('/api/v1/notifications');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }
}
