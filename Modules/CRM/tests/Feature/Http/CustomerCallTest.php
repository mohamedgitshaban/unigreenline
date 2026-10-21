<?php

namespace Modules\CRM\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\AuditLog;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Core\Support\DataScope;
use Modules\CRM\Models\Customer;
use Modules\CRM\Models\CustomerCall;
use Tests\TestCase;

class CustomerCallTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    private function user(string $role, ?User $manager = null, array $attributes = []): User
    {
        $user = User::factory()->recycle($this->tenant)->create(['manager_id' => $manager?->id, ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    private function customer(?User $owner = null): Customer
    {
        return Customer::factory()->recycle($this->tenant)->create(['sales_rep_id' => $owner?->id]);
    }

    public function test_a_rep_logs_a_call_with_text_and_customer_defaulting_to_themselves(): void
    {
        $rep = $this->user('Sales Rep');
        $customer = $this->customer($rep);
        Sanctum::actingAs($rep);

        $this->postJson('/api/v1/calls', ['customer_id' => $customer->id, 'message' => 'Asked about the March price list.'])
            ->assertCreated()
            ->assertJsonPath('data.rep.id', $rep->id)
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.message', 'Asked about the March price list.')
            ->assertJsonPath('data.direction', 'outbound');

        $this->assertDatabaseHas('customer_calls', ['customer_id' => $customer->id, 'rep_id' => $rep->id, 'tenant_id' => $this->tenant->id]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'CustomerCall', 'operation' => 'INSERT', 'user_id' => $rep->id]);
    }

    public function test_the_message_and_customer_are_required(): void
    {
        Sanctum::actingAs($this->user('Sales Rep'));

        $this->postJson('/api/v1/calls', [])->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'message']);
    }

    public function test_a_call_cannot_be_logged_against_another_companys_customer(): void
    {
        Sanctum::actingAs($this->user('Sales Rep'));
        $stranger = Customer::factory()->create();

        $this->postJson('/api/v1/calls', ['customer_id' => $stranger->id, 'message' => 'Hello'])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    public function test_call_details_are_validated(): void
    {
        $rep = $this->user('Sales Rep');
        $customer = $this->customer($rep);
        Sanctum::actingAs($rep);

        $this->postJson('/api/v1/calls', ['customer_id' => $customer->id, 'message' => 'x', 'direction' => 'sideways', 'outcome' => 'nope', 'duration_seconds' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors(['direction', 'outcome', 'duration_seconds']);
    }

    public function test_a_rep_sees_their_own_calls_and_not_a_peers(): void
    {
        $rep = $this->user('Sales Rep');
        $peer = $this->user('Sales Rep');
        $mine = CustomerCall::factory()->recycle($this->tenant)->create(['rep_id' => $rep->id, 'customer_id' => $this->customer($rep)->id]);
        $theirs = CustomerCall::factory()->recycle($this->tenant)->create(['rep_id' => $peer->id, 'customer_id' => $this->customer($peer)->id]);
        Sanctum::actingAs($rep);

        $ids = collect($this->getJson('/api/v1/calls')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
        $this->getJson("/api/v1/calls/{$theirs->id}")->assertForbidden();
        $this->getJson("/api/v1/calls/{$mine->id}")->assertOk();
    }

    public function test_a_manager_sees_the_calls_of_everyone_who_reports_to_them(): void
    {
        $manager = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $lead = $this->user('Sales Rep', $manager);
        $rep = $this->user('Sales Rep', $lead);
        $outsider = $this->user('Sales Rep');

        $own = CustomerCall::factory()->recycle($this->tenant)->create(['rep_id' => $manager->id, 'customer_id' => $this->customer($manager)->id]);
        $direct = CustomerCall::factory()->recycle($this->tenant)->create(['rep_id' => $lead->id, 'customer_id' => $this->customer($lead)->id]);
        $nested = CustomerCall::factory()->recycle($this->tenant)->create(['rep_id' => $rep->id, 'customer_id' => $this->customer($rep)->id]);
        $other = CustomerCall::factory()->recycle($this->tenant)->create(['rep_id' => $outsider->id, 'customer_id' => $this->customer($outsider)->id]);

        Sanctum::actingAs($manager);
        $ids = collect($this->getJson('/api/v1/calls')->assertOk()->json('data'))->pluck('id');

        $this->assertEqualsCanonicalizing([$own->id, $direct->id, $nested->id], $ids->all());
        $this->assertFalse($ids->contains($other->id));

        Sanctum::actingAs($lead);
        $this->assertEqualsCanonicalizing([$direct->id, $nested->id], collect($this->getJson('/api/v1/calls')->json('data'))->pluck('id')->all());
    }

    public function test_a_manager_who_sees_everything_sees_every_call(): void
    {
        $manager = $this->user('Sales Manager');
        $rep = $this->user('Sales Rep');
        $call = CustomerCall::factory()->recycle($this->tenant)->create(['rep_id' => $rep->id, 'customer_id' => $this->customer()->id]);
        Sanctum::actingAs($manager);

        $this->assertTrue(collect($this->getJson('/api/v1/calls')->json('data'))->pluck('id')->contains($call->id));
    }

    public function test_the_history_follows_the_customer_when_it_is_handed_to_someone_else(): void
    {
        $oldRep = $this->user('Sales Rep');
        $newRep = $this->user('Sales Rep');
        $customer = $this->customer($oldRep);
        $call = CustomerCall::factory()->recycle($this->tenant)->create(['rep_id' => $oldRep->id, 'customer_id' => $customer->id]);

        Sanctum::actingAs($newRep);
        $this->getJson("/api/v1/calls/{$call->id}")->assertForbidden();

        $customer->update(['sales_rep_id' => $newRep->id]);
        app(DataScope::class)->forget();

        $this->getJson("/api/v1/calls/{$call->id}")->assertOk();
        $this->assertCount(1, $this->getJson("/api/v1/customers/{$customer->id}/calls")->assertOk()->json('data'));
    }

    public function test_a_rep_cannot_log_a_call_on_behalf_of_someone_outside_their_team(): void
    {
        $manager = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $report = $this->user('Sales Rep', $manager);
        $outsider = $this->user('Sales Rep');
        $customer = $this->customer($report);
        Sanctum::actingAs($manager);

        $this->postJson('/api/v1/calls', ['customer_id' => $customer->id, 'message' => 'Logged for my rep', 'rep_id' => $report->id])
            ->assertCreated()->assertJsonPath('data.rep.id', $report->id);

        $this->postJson('/api/v1/calls', ['customer_id' => $customer->id, 'message' => 'Not mine', 'rep_id' => $outsider->id])
            ->assertUnprocessable()->assertJsonValidationErrors('rep_id');
    }

    public function test_a_manager_can_edit_a_reports_call_but_a_peer_cannot_touch_it(): void
    {
        $manager = $this->user('Sales Manager', null, ['data_scope' => 'team']);
        $rep = $this->user('Sales Rep', $manager);
        $peer = $this->user('Sales Rep');
        $call = CustomerCall::factory()->recycle($this->tenant)->create(['rep_id' => $rep->id, 'customer_id' => $this->customer($rep)->id]);

        Sanctum::actingAs($peer);
        $this->putJson("/api/v1/calls/{$call->id}", ['message' => 'tampered'])->assertForbidden();
        $this->deleteJson("/api/v1/calls/{$call->id}")->assertForbidden();

        Sanctum::actingAs($manager);
        $this->putJson("/api/v1/calls/{$call->id}", ['message' => 'Corrected note'])->assertOk()->assertJsonPath('data.message', 'Corrected note');
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'CustomerCall', 'operation' => 'UPDATE', 'entity_id' => $call->id]);

        $this->deleteJson("/api/v1/calls/{$call->id}")->assertForbidden();

        $admin = $this->user('Administrator');
        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/calls/{$call->id}")->assertNoContent();
        $this->assertDatabaseMissing('customer_calls', ['id' => $call->id]);
    }

    public function test_calls_can_be_filtered_by_customer_direction_and_search(): void
    {
        $rep = $this->user('Sales Rep');
        $first = $this->customer($rep);
        $second = $this->customer($rep);
        $a = CustomerCall::factory()->recycle($this->tenant)->create(['rep_id' => $rep->id, 'customer_id' => $first->id, 'direction' => 'inbound', 'message' => 'Complained about delivery']);
        CustomerCall::factory()->recycle($this->tenant)->create(['rep_id' => $rep->id, 'customer_id' => $second->id, 'direction' => 'outbound', 'message' => 'Quoted prices']);
        Sanctum::actingAs($rep);

        $this->assertSame([$a->id], collect($this->getJson("/api/v1/calls?customer_id={$first->id}")->json('data'))->pluck('id')->all());
        $this->assertSame([$a->id], collect($this->getJson('/api/v1/calls?direction=inbound')->json('data'))->pluck('id')->all());
        $this->assertCount(1, $this->getJson('/api/v1/calls?search=delivery')->json('data'));
    }

    public function test_another_companys_call_is_not_found(): void
    {
        $admin = $this->user('Administrator');
        $foreign = CustomerCall::factory()->create();
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/calls/{$foreign->id}")->assertNotFound();
        $this->putJson("/api/v1/calls/{$foreign->id}", ['message' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/calls/{$foreign->id}")->assertNotFound();
        $this->assertSame(0, AuditLog::query()->where('entity_id', $foreign->id)->count());
    }

    public function test_the_role_must_allow_logging_calls(): void
    {
        $this->user('Sales Rep');
        $viewer = $this->user('Warehouse Manager');
        Sanctum::actingAs($viewer);

        $this->postJson('/api/v1/calls', ['customer_id' => $this->customer()->id, 'message' => 'x'])->assertForbidden();
    }
}
