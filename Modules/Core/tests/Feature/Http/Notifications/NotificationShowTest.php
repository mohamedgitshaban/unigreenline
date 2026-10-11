<?php

namespace Modules\Core\Tests\Feature\Http\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class NotificationShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_their_own_notification(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        Sanctum::actingAs($user);

        $notification = Notification::factory()->recycle($tenant)->create(['user_id' => $user->id]);

        $response = $this->getJson("/api/v1/notifications/{$notification->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $notification->id);
    }

    public function test_user_can_view_a_tenant_wide_broadcast(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        Sanctum::actingAs($user);

        $notification = Notification::factory()->recycle($tenant)->create(['user_id' => null]);

        $response = $this->getJson("/api/v1/notifications/{$notification->id}");

        $response->assertOk();
    }

    public function test_another_users_notification_is_not_found(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $otherUser = User::factory()->recycle($tenant)->create();
        Sanctum::actingAs($user);

        $notification = Notification::factory()->recycle($tenant)->create(['user_id' => $otherUser->id]);

        $response = $this->getJson("/api/v1/notifications/{$notification->id}");

        $response->assertNotFound();
    }

    public function test_a_broadcast_from_another_tenant_is_not_found(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $notification = Notification::factory()->create(['user_id' => null]);

        $response = $this->getJson("/api/v1/notifications/{$notification->id}");

        $response->assertNotFound();
    }
}
