<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Core\Notifications\ChangeNotificationRegistry;
use Modules\Core\Support\PermissionCatalogue;
use Modules\CRM\Models\Customer;
use Tests\TestCase;

/**
 * Changes to a record reach the people it concerns — its owner and everyone the owner reports to — with a link to it.
 */
class ChangeNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $manager;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->manager = $this->user('Sales Manager');
        $this->rep = $this->user('Sales Rep', $this->manager);
    }

    private function user(string $role, ?User $manager = null, array $attributes = []): User
    {
        $user = User::factory()->recycle($this->tenant)->create(['manager_id' => $manager?->id, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    private function notificationsFor(User $user)
    {
        return Notification::query()->where('user_id', $user->id)->get();
    }

    public function test_an_owner_hears_when_someone_else_changes_their_customer_with_a_link_to_it(): void
    {
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id, 'name' => 'Nile Farm']);
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/v1/customers/{$customer->id}", ['name' => 'Nile Farms Ltd', 'city' => 'Aswan'])->assertOk();

        $note = $this->notificationsFor($this->rep)->sole();
        $this->assertSame('Customer updated', $note->title);
        $this->assertStringContainsString($this->manager->name, $note->body);
        $this->assertStringContainsString('Nile Farms Ltd', $note->body);
        $this->assertStringContainsString('name, city', $note->body);
        $this->assertSame('customer.update', $note->type);
        $this->assertSame('crm', $note->link_module);
        $this->assertSame('Customer', $note->link_entity);
        $this->assertSame($customer->id, $note->link_id);
        $this->assertSame("/api/v1/customers/{$customer->id}", $note->link_url);
        $this->assertSame($this->manager->id, $note->actor_id);
        $this->assertTrue($note->unread);
        $this->assertCount(0, $this->notificationsFor($this->manager), 'nobody is told about their own change');
    }

    public function test_the_manager_hears_when_their_rep_changes_a_customer(): void
    {
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id]);
        Sanctum::actingAs($this->rep);

        $this->putJson("/api/v1/customers/{$customer->id}", ['city' => 'Luxor'])->assertOk();

        $this->assertCount(1, $this->notificationsFor($this->manager));
        $this->assertCount(0, $this->notificationsFor($this->rep));
    }

    public function test_every_manager_up_the_line_hears_about_it(): void
    {
        $boss = $this->user('Sales Manager');
        $this->manager->update(['manager_id' => $boss->id]);
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id]);
        Sanctum::actingAs($this->rep);

        $this->putJson("/api/v1/customers/{$customer->id}", ['city' => 'Luxor'])->assertOk();

        $this->assertCount(1, $this->notificationsFor($this->manager));
        $this->assertCount(1, $this->notificationsFor($boss));
    }

    public function test_a_new_customer_announces_itself_to_its_owner_and_managers(): void
    {
        Sanctum::actingAs($this->user('Administrator'));

        $this->postJson('/api/v1/customers', ['name' => 'Fresh Clinic', 'type' => 'Clinic', 'sales_rep_id' => $this->rep->id])->assertCreated();

        $this->assertSame('Customer added', $this->notificationsFor($this->rep)->sole()->title);
        $this->assertSame('Customer added', $this->notificationsFor($this->manager)->sole()->title);
    }

    public function test_handing_a_customer_over_tells_the_old_and_the_new_owner(): void
    {
        $other = $this->user('Sales Rep');
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id]);
        Sanctum::actingAs($this->user('Administrator'));

        $this->postJson("/api/v1/customers/{$customer->id}/assign", ['sales_rep_id' => $other->id])->assertOk();

        $this->assertCount(1, $this->notificationsFor($this->rep), 'the previous owner');
        $this->assertCount(1, $this->notificationsFor($other), 'the new owner');
        $this->assertCount(1, $this->notificationsFor($this->manager), 'the previous owner\'s manager');
    }

    public function test_a_deleted_customer_is_announced_without_a_link(): void
    {
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id]);
        Sanctum::actingAs($this->user('Administrator'));

        $this->deleteJson("/api/v1/customers/{$customer->id}")->assertNoContent();

        $note = $this->notificationsFor($this->rep)->sole();
        $this->assertSame('Customer deleted', $note->title);
        $this->assertNull($note->link_url);
        $this->assertNull($note->link_id);
    }

    public function test_people_who_may_not_open_the_record_are_not_told(): void
    {
        $storeman = $this->user('Warehouse Employee');
        $this->rep->update(['manager_id' => $storeman->id]);
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id]);
        Sanctum::actingAs($this->user('Administrator'));

        $this->putJson("/api/v1/customers/{$customer->id}", ['city' => 'Luxor'])->assertOk();

        $this->assertCount(0, $this->notificationsFor($storeman));
        $this->assertCount(1, $this->notificationsFor($this->rep));
    }

    public function test_inactive_users_are_not_told(): void
    {
        $this->rep->update(['status' => 'inactive']);
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id]);
        Sanctum::actingAs($this->user('Administrator'));

        $this->putJson("/api/v1/customers/{$customer->id}", ['city' => 'Luxor'])->assertOk();

        $this->assertSame(0, Notification::query()->where('user_id', $this->rep->id)->count());
    }

    public function test_a_call_logged_on_a_customer_tells_the_customers_owner_and_managers(): void
    {
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id]);
        $colleague = $this->user('Sales Rep');
        Sanctum::actingAs($colleague);

        $this->postJson('/api/v1/calls', ['customer_id' => $customer->id, 'message' => 'Asked for a quote.', 'subject' => 'Quote'])->assertCreated();

        $note = $this->notificationsFor($this->rep)->sole();
        $this->assertSame('Call added', $note->title);
        $this->assertSame('/api/v1/calls/'.$note->link_id, $note->link_url);
        $this->assertCount(0, $this->notificationsFor($colleague));
    }

    public function test_a_failed_request_announces_nothing(): void
    {
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id]);
        Sanctum::actingAs($this->manager);

        $this->putJson("/api/v1/customers/{$customer->id}", ['type' => 'Spaceship'])->assertUnprocessable();

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_notifications_can_be_listed_counted_and_marked_read_one_by_one(): void
    {
        $customer = Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $this->rep->id]);
        Sanctum::actingAs($this->manager);
        $this->putJson("/api/v1/customers/{$customer->id}", ['city' => 'Luxor'])->assertOk();
        $this->putJson("/api/v1/customers/{$customer->id}", ['city' => 'Giza'])->assertOk();

        Sanctum::actingAs($this->rep);
        $list = $this->getJson('/api/v1/notifications?module=crm')->assertOk();
        $list->assertJsonCount(2, 'data')->assertJsonPath('data.0.link.url', "/api/v1/customers/{$customer->id}");

        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.total', 2)->assertJsonPath('data.by_module.crm', 2);

        $id = $list->json('data.0.id');
        $this->putJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('data.unread', false);
        $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.total', 1);
        $this->assertCount(1, $this->getJson('/api/v1/notifications?unread=1')->json('data'));

        Sanctum::actingAs($this->manager);
        $this->putJson("/api/v1/notifications/{$id}/read")->assertNotFound();
    }

    public function test_every_watched_record_type_points_at_a_real_permission_and_route(): void
    {
        $permissions = PermissionCatalogue::names();
        $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->all();

        $watched = app(ChangeNotificationRegistry::class)->all();
        $this->assertNotEmpty($watched);

        foreach ($watched as $key => $entity) {
            $this->assertContains($entity->permission, $permissions, "{$key} names an unknown permission");
            $this->assertTrue(
                collect($routes)->contains(fn (string $uri) => preg_match('#^api/v1/'.preg_quote($entity->path, '#').'/\{\w+\}$#', $uri) === 1),
                "{$key} links to api/v1/{$entity->path}/{id}, which is not a route",
            );
        }
    }
}
