<?php

namespace Modules\Core\Tests\Feature\Http\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

class NotificationReadAllTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_own_and_broadcast_notifications_as_read(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        Sanctum::actingAs($user);

        $own = Notification::factory()->recycle($tenant)->create(['user_id' => $user->id, 'unread' => true]);
        $broadcast = Notification::factory()->recycle($tenant)->create(['user_id' => null, 'unread' => true]);

        $response = $this->putJson('/api/v1/notifications/read-all');

        $response->assertOk();
        $this->assertFalse($own->fresh()->unread);
        $this->assertFalse($broadcast->fresh()->unread);
    }

    public function test_does_not_mark_another_users_personal_notification_as_read(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $other = User::factory()->recycle($tenant)->create();
        Sanctum::actingAs($user);

        $othersNotification = Notification::factory()->recycle($tenant)->create(['user_id' => $other->id, 'unread' => true]);

        $this->putJson('/api/v1/notifications/read-all')->assertOk();

        $this->assertTrue($othersNotification->fresh()->unread);
    }
}
