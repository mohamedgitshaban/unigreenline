<?php

namespace Modules\Recap\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Warehouse;
use Modules\Recap\Models\Recap;
use Tests\TestCase;

class RecapTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Warehouse $warehouse;

    private Account $inventoryAccount;

    private Account $writeOffAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        $this->inventoryAccount = Account::factory()->recycle($this->tenant)->create(['code' => '1300', 'type' => 'Asset', 'balance' => 10000]);
        $this->writeOffAccount = Account::factory()->recycle($this->tenant)->create(['code' => '6300', 'type' => 'Expense', 'balance' => 0]);
    }

    public function test_creates_a_draft_recap_that_snapshots_the_batches_without_touching_stock(): void
    {
        $this->actingAsRole('Warehouse Manager');
        $batch = $this->batch(['qty_cartons' => 10, 'cost_per_carton' => '50.00']);

        $this->postJson('/api/v1/recaps', [
            'warehouse_id' => $this->warehouse->id,
            'reason' => 'damaged',
            'lines' => [['batch_id' => $batch->id, 'qty_cartons' => 4]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_value', '200.00')
            ->assertJsonPath('data.lines.0.batch_no', $batch->batch_no);

        $this->assertSame(10, $batch->fresh()->qty_cartons);
        $this->assertSame('0.00', $this->writeOffAccount->fresh()->balance);
    }

    public function test_approving_removes_the_stock_and_posts_the_write_off_to_the_chart_of_accounts(): void
    {
        $manager = $this->actingAsRole('Warehouse Manager');
        $batch = $this->batch(['qty_cartons' => 10, 'cost_per_carton' => '50.00', 'exp_date' => now()->subDays(3)->toDateString()]);
        $recap = $this->draftRecap($batch, 6);

        $this->postJson("/api/v1/recaps/{$recap->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.total_value', '300.00')
            ->assertJsonPath('data.approved_by.id', $manager->id);

        $this->assertSame(4, $batch->fresh()->qty_cartons);
        $this->assertSame('300.00', $this->writeOffAccount->fresh()->balance);
        $this->assertSame('9700.00', $this->inventoryAccount->fresh()->balance);
        $journalId = $recap->fresh()->journal_entry_id;
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journalId, 'account_code' => '6300', 'debit' => '300.00']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journalId, 'account_code' => '1300', 'credit' => '300.00']);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Recap', 'entity_id' => $recap->id, 'operation' => 'APPROVE']);
    }

    public function test_approving_is_refused_when_the_batch_no_longer_holds_enough_stock(): void
    {
        $this->actingAsRole('Warehouse Manager');
        $batch = $this->batch(['qty_cartons' => 10]);
        $recap = $this->draftRecap($batch, 8);
        $batch->update(['qty_cartons' => 3]);

        $this->postJson("/api/v1/recaps/{$recap->id}/approve")->assertUnprocessable();

        $this->assertSame('draft', $recap->fresh()->status);
        $this->assertSame(3, $batch->fresh()->qty_cartons);
        $this->assertSame('0.00', $this->writeOffAccount->fresh()->balance);
    }

    public function test_an_approved_recap_cannot_be_approved_edited_or_deleted(): void
    {
        $this->actingAsRole('Administrator');
        $recap = Recap::factory()->recycle($this->tenant)->approved()->create(['warehouse_id' => $this->warehouse->id]);

        $this->postJson("/api/v1/recaps/{$recap->id}/approve")->assertUnprocessable();
        $this->putJson("/api/v1/recaps/{$recap->id}", ['notes' => 'x'])->assertUnprocessable();
        $this->deleteJson("/api/v1/recaps/{$recap->id}")->assertUnprocessable();
    }

    public function test_rejecting_posts_nothing(): void
    {
        $this->actingAsRole('Administrator');
        $batch = $this->batch(['qty_cartons' => 10]);
        $recap = $this->draftRecap($batch, 2);

        $this->postJson("/api/v1/recaps/{$recap->id}/reject", ['reason' => 'Counted wrong'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(10, $batch->fresh()->qty_cartons);
        $this->assertSame('0.00', $this->writeOffAccount->fresh()->balance);
    }

    public function test_edits_replace_the_lines_of_a_draft_and_deleting_removes_it(): void
    {
        $this->actingAsRole('Administrator');
        $first = $this->batch(['qty_cartons' => 10, 'cost_per_carton' => '10.00']);
        $second = $this->batch(['qty_cartons' => 10, 'cost_per_carton' => '20.00']);
        $recap = $this->draftRecap($first, 1);

        $this->putJson("/api/v1/recaps/{$recap->id}", [
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['batch_id' => $second->id, 'qty_cartons' => 5]],
        ])
            ->assertOk()
            ->assertJsonPath('data.total_value', '100.00')
            ->assertJsonCount(1, 'data.lines');

        $this->deleteJson("/api/v1/recaps/{$recap->id}")->assertNoContent();
        $this->assertDatabaseMissing('recaps', ['id' => $recap->id]);
        $this->assertDatabaseMissing('recap_lines', ['recap_id' => $recap->id]);
    }

    public function test_lists_expired_batches_and_drafts_one_recap_per_warehouse_without_duplicating(): void
    {
        $this->actingAsRole('Warehouse Manager');
        $expired = $this->batch(['qty_cartons' => 7, 'cost_per_carton' => '10.00', 'exp_date' => now()->subDay()->toDateString()]);
        $this->batch(['exp_date' => now()->addMonth()->toDateString()]);
        $this->batch(['qty_cartons' => 0, 'exp_date' => now()->subDay()->toDateString()]);

        $this->getJson('/api/v1/recaps/expired-batches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.batch_id', $expired->id)
            ->assertJsonPath('data.0.value', 70);

        $this->postJson('/api/v1/recaps/from-expired')
            ->assertCreated()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'expired')
            ->assertJsonPath('data.0.total_value', '70.00');

        $this->postJson('/api/v1/recaps/from-expired')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(7, $expired->fresh()->qty_cartons);
    }

    public function test_the_console_command_drafts_recaps_for_every_tenant(): void
    {
        $this->batch(['exp_date' => now()->subDay()->toDateString()]);

        $this->artisan('recap:generate-expired')->assertSuccessful();

        $this->assertSame(1, Recap::query()->where('status', 'draft')->count());
    }

    public function test_permissions_are_enforced_per_action(): void
    {
        $batch = $this->batch(['qty_cartons' => 10]);
        $recap = $this->draftRecap($batch, 1);

        $this->actingAsRole('Warehouse Employee');
        $this->getJson('/api/v1/recaps')->assertForbidden();

        $this->actingAsRole('Accountant');
        $this->getJson('/api/v1/recaps')->assertOk();
        $this->postJson("/api/v1/recaps/{$recap->id}/approve")->assertForbidden();
        $this->deleteJson("/api/v1/recaps/{$recap->id}")->assertForbidden();
    }

    public function test_another_tenants_recap_is_not_found(): void
    {
        $this->actingAsRole('Administrator');
        $foreign = Recap::factory()->create();

        $this->getJson("/api/v1/recaps/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/v1/recaps/{$foreign->id}/approve")->assertNotFound();
        $this->deleteJson("/api/v1/recaps/{$foreign->id}")->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function batch(array $attributes = []): InventoryBatch
    {
        return InventoryBatch::factory()->recycle($this->tenant)->create(['warehouse_id' => $this->warehouse->id] + $attributes);
    }

    private function draftRecap(InventoryBatch $batch, int $qty): Recap
    {
        $recap = Recap::factory()->recycle($this->tenant)->create(['warehouse_id' => $this->warehouse->id]);
        $recap->lines()->create([
            'batch_id' => $batch->id,
            'product_id' => $batch->product_id,
            'batch_no' => $batch->batch_no,
            'exp_date' => $batch->exp_date,
            'qty_cartons' => $qty,
            'cost_per_carton' => $batch->cost_per_carton,
            'line_value' => $qty * (float) $batch->cost_per_carton,
        ]);
        $recap->update(['total_value' => $qty * (float) $batch->cost_per_carton]);

        return $recap;
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        $this->warehouse->users()->attach($user);
        Sanctum::actingAs($user);

        return $user;
    }
}
