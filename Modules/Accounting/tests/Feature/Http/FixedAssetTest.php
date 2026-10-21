<?php

namespace Modules\Accounting\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AssetCategory;
use Modules\Accounting\Models\FixedAsset;
use Modules\Accounting\Models\FixedAssetDepreciation;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private User $accountant;

    private AssetCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->ensureLedgerAccounts($this->tenant);
        $this->accountant = $this->user('Accountant');
        $this->category = AssetCategory::factory()->recycle($this->tenant)->create(['name' => 'Lab equipment', 'duration_periods' => 12]);
        Sanctum::actingAs($this->accountant);
    }

    private function user(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);

        return $user;
    }

    private function balance(string $code): float
    {
        return (float) Account::query()->where('tenant_id', $this->tenant->id)->where('code', $code)->value('balance');
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + ['name' => 'Centrifuge', 'category_id' => $this->category->id, 'acquisition_date' => '2026-01-01', 'original_value' => 12000, 'duration_periods' => 12];
    }

    private function create(array $overrides = []): string
    {
        return $this->postJson('/api/v1/fixed-assets', $this->payload($overrides))->assertCreated()->json('data.id');
    }

    public function test_an_asset_is_registered_as_a_draft_with_its_depreciation_board_taken_from_its_category(): void
    {
        $response = $this->postJson('/api/v1/fixed-assets', ['name' => 'Centrifuge', 'category_id' => $this->category->id, 'acquisition_date' => '2026-01-01', 'original_value' => 12000])->assertCreated();

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', 'FA00001')
            ->assertJsonPath('data.method', 'straight_line')
            ->assertJsonPath('data.duration_periods', 12)
            ->assertJsonPath('data.book_value', '12000.00')
            ->assertJsonPath('data.accounts.asset', '1510')
            ->assertJsonPath('data.depreciations_count', 12)
            ->assertJsonPath('data.next_depreciation.amount', '1000.00');

        $this->assertDatabaseHas('audit_log', ['entity_type' => 'FixedAsset', 'operation' => 'INSERT', 'user_id' => $this->accountant->id]);
        $this->assertSame('FA00002', $this->postJson('/api/v1/fixed-assets', $this->payload())->json('data.number'));
    }

    public function test_the_salvage_value_defaults_from_the_category_percentage(): void
    {
        $this->category->update(['salvage_pct' => 10]);

        $this->postJson('/api/v1/fixed-assets', $this->payload())->assertCreated()->assertJsonPath('data.salvage_value', '1200.00')->assertJsonPath('data.depreciable_value', '10800.00');
    }

    public function test_the_values_are_validated(): void
    {
        $this->postJson('/api/v1/fixed-assets', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'category_id', 'acquisition_date', 'original_value']);
        $this->postJson('/api/v1/fixed-assets', $this->payload(['original_value' => 0]))->assertUnprocessable()->assertJsonValidationErrors('original_value');
        $this->postJson('/api/v1/fixed-assets', $this->payload(['method' => 'magic', 'period_months' => 5]))->assertUnprocessable()->assertJsonValidationErrors(['method', 'period_months']);
        $this->postJson('/api/v1/fixed-assets', $this->payload(['expense_account_code' => '9999']))->assertUnprocessable()->assertJsonValidationErrors('expense_account_code');
        $this->postJson('/api/v1/fixed-assets', $this->payload(['category_id' => AssetCategory::factory()->create()->id]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->postJson('/api/v1/fixed-assets', $this->payload(['salvage_value' => 99999]))->assertUnprocessable()->assertJsonPath('message', 'The salvage value cannot be more than the original value.');
    }

    public function test_the_board_can_be_previewed_without_saving_anything(): void
    {
        $this->postJson('/api/v1/fixed-assets/preview', ['acquisition_date' => '2026-01-01', 'original_value' => 3000, 'duration_periods' => 3, 'method' => 'straight_line'])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.amount', 1000)
            ->assertJsonPath('summary.total', '3000.00');

        $this->assertSame(0, FixedAsset::query()->count());
    }

    public function test_a_draft_can_be_changed_and_its_board_is_redrawn(): void
    {
        $id = $this->create();

        $this->putJson("/api/v1/fixed-assets/{$id}", ['original_value' => 6000, 'duration_periods' => 6])
            ->assertOk()->assertJsonPath('data.depreciations_count', 6)->assertJsonPath('data.next_depreciation.amount', '1000.00');
    }

    public function test_activating_puts_the_asset_on_the_books_and_can_book_the_purchase(): void
    {
        $id = $this->create();

        $this->postJson("/api/v1/fixed-assets/{$id}/activate", ['acquisition_account_code' => '1120'])
            ->assertOk()->assertJsonPath('data.status', 'running');

        $this->assertEquals(12000, $this->balance('1510'));
        $this->assertEquals(-12000, $this->balance('1120'), 'the bank is credited');
        $this->assertNotNull(FixedAsset::query()->findOrFail($id)->acquisition_journal_entry_id);
    }

    public function test_activating_without_an_account_books_nothing(): void
    {
        $id = $this->create();

        $this->postJson("/api/v1/fixed-assets/{$id}/activate")->assertOk()->assertJsonPath('data.status', 'running');

        $this->assertEquals(0, $this->balance('1510'));
        $this->assertNull(FixedAsset::query()->findOrFail($id)->acquisition_journal_entry_id);
        $this->postJson("/api/v1/fixed-assets/{$id}/activate")->assertUnprocessable();
    }

    public function test_depreciation_is_booked_line_by_line_and_only_when_due(): void
    {
        $id = $this->create(['acquisition_date' => now()->subMonths(2)->startOfMonth()->toDateString()]);
        $this->postJson("/api/v1/fixed-assets/{$id}/activate")->assertOk();
        $lines = FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->orderBy('sequence')->get();

        $this->postJson("/api/v1/fixed-assets/{$id}/depreciations/{$lines[1]->id}/post")->assertUnprocessable()->assertJsonPath('message', 'Earlier depreciation lines have to be booked first.');

        $this->postJson("/api/v1/fixed-assets/{$id}/depreciations/{$lines[0]->id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $this->assertEquals(1000, $this->balance('6500'));
        $this->assertEquals(-1000, $this->balance('1590'), 'accumulated depreciation is a credit against the asset');
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $lines[0]->fresh()->journal_entry_id, 'account_code' => '6500', 'debit' => '1000.00']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $lines[0]->fresh()->journal_entry_id, 'account_code' => '1590', 'credit' => '1000.00']);
        $this->getJson("/api/v1/fixed-assets/{$id}")->assertJsonPath('data.accumulated_depreciation', '1000.00')->assertJsonPath('data.book_value', '11000.00');

        $this->postJson("/api/v1/fixed-assets/{$id}/depreciations/{$lines[0]->id}/post")->assertUnprocessable()->assertJsonPath('message', 'This depreciation has already been booked.');

        $future = $lines->last();
        $this->postJson("/api/v1/fixed-assets/{$id}/depreciations/{$future->id}/post")->assertUnprocessable();
    }

    public function test_a_line_that_is_not_due_yet_is_refused(): void
    {
        $id = $this->create(['acquisition_date' => now()->addMonths(3)->startOfMonth()->toDateString()]);
        $this->postJson("/api/v1/fixed-assets/{$id}/activate")->assertOk();
        $first = FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->orderBy('sequence')->first();

        $this->postJson("/api/v1/fixed-assets/{$id}/depreciations/{$first->id}/post")->assertUnprocessable()->assertJsonPath('message', "This depreciation is not due until {$first->depreciation_date->toDateString()}.");
    }

    public function test_running_the_depreciation_books_everything_due_for_every_asset(): void
    {
        $first = $this->create(['acquisition_date' => '2026-01-01']);
        $second = $this->create(['name' => 'Fridge', 'acquisition_date' => '2026-01-01', 'original_value' => 2400, 'duration_periods' => 24]);
        $this->postJson("/api/v1/fixed-assets/{$first}/activate")->assertOk();
        $this->postJson("/api/v1/fixed-assets/{$second}/activate")->assertOk();
        $draft = $this->create(['name' => 'Not yet']);

        $this->postJson('/api/v1/fixed-assets/depreciate', ['date' => '2026-03-31'])
            ->assertOk()->assertJsonPath('data.lines', 6)->assertJsonPath('data.assets', 2)->assertJsonPath('data.total', 3300)->assertJsonPath('data.failed', []);

        $this->assertEquals(3300, $this->balance('6500'));
        $this->assertSame(0, FixedAssetDepreciation::query()->where('fixed_asset_id', $draft)->where('status', 'posted')->count());

        $this->postJson('/api/v1/fixed-assets/depreciate', ['date' => '2026-03-31'])->assertOk()->assertJsonPath('data.lines', 0);
        $this->assertEquals(3300, $this->balance('6500'), 'booking twice changes nothing');
    }

    public function test_the_last_depreciation_makes_the_asset_fully_depreciated(): void
    {
        $id = $this->create(['acquisition_date' => '2025-01-01', 'duration_periods' => 3, 'original_value' => 3000]);
        $this->postJson("/api/v1/fixed-assets/{$id}/activate")->assertOk();

        $this->postJson('/api/v1/fixed-assets/depreciate', ['date' => '2026-01-01'])->assertOk()->assertJsonPath('data.lines', 3);

        $this->getJson("/api/v1/fixed-assets/{$id}")->assertJsonPath('data.status', 'fully_depreciated')->assertJsonPath('data.book_value', '0.00')->assertJsonPath('data.depreciated_pct', 100);
    }

    public function test_the_command_books_due_depreciation_and_leaves_manual_assets_alone(): void
    {
        $auto = $this->create(['acquisition_date' => '2026-01-01']);
        $manual = $this->create(['name' => 'Manual', 'acquisition_date' => '2026-01-01', 'auto_post' => false]);
        $this->postJson("/api/v1/fixed-assets/{$auto}/activate")->assertOk();
        $this->postJson("/api/v1/fixed-assets/{$manual}/activate")->assertOk();

        $this->artisan('assets:depreciate', ['--date' => '2026-02-28', '--tenant' => $this->tenant->id])->assertSuccessful();

        $this->assertSame(2, FixedAssetDepreciation::query()->where('fixed_asset_id', $auto)->where('status', 'posted')->count());
        $this->assertSame(0, FixedAssetDepreciation::query()->where('fixed_asset_id', $manual)->where('status', 'posted')->count());

        $this->artisan('assets:depreciate', ['--date' => '2026-02-28', '--tenant' => $this->tenant->id, '--all' => true])->assertSuccessful();
        $this->assertSame(2, FixedAssetDepreciation::query()->where('fixed_asset_id', $manual)->where('status', 'posted')->count());
    }

    public function test_a_paused_asset_books_nothing_until_it_is_resumed_and_the_rest_of_the_board_moves_with_it(): void
    {
        $id = $this->create(['acquisition_date' => '2026-01-01']);
        $this->postJson("/api/v1/fixed-assets/{$id}/activate")->assertOk();
        $this->postJson('/api/v1/fixed-assets/depreciate', ['date' => '2026-02-28'])->assertOk();

        $this->postJson("/api/v1/fixed-assets/{$id}/pause")->assertOk()->assertJsonPath('data.status', 'paused');
        $this->postJson('/api/v1/fixed-assets/depreciate', ['date' => '2026-12-31'])->assertOk()->assertJsonPath('data.lines', 0);
        $this->postJson("/api/v1/fixed-assets/{$id}/pause")->assertUnprocessable();

        $this->postJson("/api/v1/fixed-assets/{$id}/resume", ['date' => '2026-06-10'])->assertOk()->assertJsonPath('data.status', 'running');

        $lines = FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->where('status', 'planned')->orderBy('sequence')->get();
        $this->assertCount(10, $lines);
        $this->assertSame('2026-06-30', $lines->first()->depreciation_date->toDateString());
        $this->assertEquals(10000, $lines->sum('amount'));
        $this->assertSame(3, $lines->first()->sequence);
        $this->postJson("/api/v1/fixed-assets/{$id}/resume")->assertUnprocessable();
    }

    public function test_modifying_redraws_what_is_left_from_the_current_book_value(): void
    {
        $id = $this->create(['acquisition_date' => '2026-01-01']);
        $this->postJson("/api/v1/fixed-assets/{$id}/activate")->assertOk();
        $this->postJson('/api/v1/fixed-assets/depreciate', ['date' => '2026-03-31'])->assertOk();

        $this->postJson("/api/v1/fixed-assets/{$id}/modify", ['remaining_periods' => 6, 'salvage_value' => 900])
            ->assertOk()->assertJsonPath('data.salvage_value', '900.00')->assertJsonPath('data.duration_periods', 9);

        $planned = FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->where('status', 'planned')->orderBy('sequence')->get();
        $this->assertCount(6, $planned);
        $this->assertSame(4, $planned->first()->sequence);
        $this->assertSame('2026-04-30', $planned->first()->depreciation_date->toDateString());
        $this->assertEquals((9000 - 900) / 6, (float) $planned->first()->amount);
        $this->assertEquals(900, (float) $planned->last()->book_value_after);
        $this->assertSame(3, FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->where('status', 'posted')->count(), 'what was booked stays');
    }

    public function test_the_salvage_value_cannot_exceed_what_the_asset_is_worth(): void
    {
        $id = $this->create();
        $this->postJson("/api/v1/fixed-assets/{$id}/activate")->assertOk();

        $this->postJson("/api/v1/fixed-assets/{$id}/modify", ['remaining_periods' => 6, 'salvage_value' => 20000])->assertUnprocessable();
        $this->postJson("/api/v1/fixed-assets/{$id}/modify", ['remaining_periods' => 0])->assertUnprocessable()->assertJsonValidationErrors('remaining_periods');
    }

    public function test_selling_an_asset_above_its_book_value_books_a_gain(): void
    {
        $id = $this->create(['acquisition_date' => '2026-01-01', 'original_value' => 12000]);
        $this->postJson("/api/v1/fixed-assets/{$id}/activate", ['acquisition_account_code' => '1120'])->assertOk();
        $this->postJson('/api/v1/fixed-assets/depreciate', ['date' => '2026-04-30'])->assertOk();

        Sanctum::actingAs($this->user('Owner'));
        $this->postJson("/api/v1/fixed-assets/{$id}/dispose", ['date' => '2026-04-30', 'sale_value' => 9500, 'proceeds_account_code' => '1110', 'reason' => 'Upgraded'])
            ->assertOk()->assertJsonPath('data.status', 'sold')->assertJsonPath('data.disposal.value', '9500.00');

        $this->assertEquals(0, $this->balance('1510'), 'cost cleared');
        $this->assertEquals(0, $this->balance('1590'), 'accumulated depreciation cleared');
        $this->assertEquals(1500, $this->balance('4400'), '9500 for something worth 8000');
        $this->assertEquals(0, $this->balance('6700'));
        $this->assertEquals(9500, $this->balance('1110'));
        $this->assertSame(0, FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->where('status', 'planned')->count(), 'the rest of the board is dropped');
    }

    public function test_selling_below_the_book_value_books_a_loss_and_scrapping_writes_it_all_off(): void
    {
        $sold = $this->create(['acquisition_date' => '2026-01-01', 'original_value' => 12000]);
        $this->postJson("/api/v1/fixed-assets/{$sold}/activate")->assertOk();
        $scrapped = $this->create(['name' => 'Broken', 'acquisition_date' => '2026-01-01', 'original_value' => 3000, 'duration_periods' => 3]);
        $this->postJson("/api/v1/fixed-assets/{$scrapped}/activate")->assertOk();
        Sanctum::actingAs($this->user('Owner'));
        $this->postJson("/api/v1/fixed-assets/{$sold}/dispose", ['date' => '2026-02-28', 'sale_value' => 5000, 'proceeds_account_code' => '1120'])->assertOk();
        $this->assertEquals(5000, $this->balance('6700'), '5000 for something worth 10000');

        $this->postJson("/api/v1/fixed-assets/{$scrapped}/dispose", ['date' => '2026-01-31'])->assertOk()->assertJsonPath('data.status', 'disposed');
        $this->assertEquals(7000, $this->balance('6700'), 'the 2000 left of the scrapped asset is a loss');
    }

    public function test_disposal_needs_proceeds_account_when_there_are_proceeds_and_a_live_asset(): void
    {
        $id = $this->create();
        $owner = $this->user('Owner');

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/fixed-assets/{$id}/dispose", ['date' => '2026-02-01'])->assertUnprocessable();
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/fixed-assets/{$id}/activate")->assertOk();
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/fixed-assets/{$id}/dispose", ['date' => '2026-02-01', 'sale_value' => 100])->assertUnprocessable()->assertJsonPath('message', 'Say which account the proceeds go to (cash, bank or receivables).');
        $this->postJson("/api/v1/fixed-assets/{$id}/dispose", ['date' => '2025-12-01'])->assertUnprocessable()->assertJsonPath('message', 'An asset cannot be disposed of before it was acquired.');
        $this->postJson("/api/v1/fixed-assets/{$id}/dispose", ['date' => '2026-02-01'])->assertOk();
        $this->postJson("/api/v1/fixed-assets/{$id}/dispose", ['date' => '2026-02-01'])->assertUnprocessable();
    }

    public function test_only_a_draft_can_have_its_value_changed_cancelled_or_deleted(): void
    {
        $id = $this->create();
        $this->postJson("/api/v1/fixed-assets/{$id}/activate")->assertOk();

        $this->putJson("/api/v1/fixed-assets/{$id}", ['original_value' => 1])->assertUnprocessable();
        $this->putJson("/api/v1/fixed-assets/{$id}", ['location' => 'Lab 2', 'serial_number' => 'SN-9'])->assertOk()->assertJsonPath('data.location', 'Lab 2');
        $this->postJson("/api/v1/fixed-assets/{$id}/cancel")->assertUnprocessable();

        $draft = $this->create(['name' => 'Draft']);
        $this->postJson("/api/v1/fixed-assets/{$draft}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        Sanctum::actingAs($this->user('Administrator'));
        $this->deleteJson("/api/v1/fixed-assets/{$id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/fixed-assets/{$draft}")->assertNoContent();
        $this->assertDatabaseMissing('fixed_assets', ['id' => $draft]);
    }

    public function test_asset_lists_filter_summarise_and_forecast(): void
    {
        $a = $this->create(['acquisition_date' => '2026-01-01']);
        $this->create(['name' => 'Second draft']);
        $this->postJson("/api/v1/fixed-assets/{$a}/activate")->assertOk();
        $this->postJson('/api/v1/fixed-assets/depreciate', ['date' => '2026-02-28'])->assertOk();

        $this->getJson('/api/v1/fixed-assets?status=draft')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/fixed-assets?search=Second')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/fixed-assets?category_id={$this->category->id}")->assertJsonCount(2, 'data');

        $summary = $this->getJson('/api/v1/fixed-assets/summary')->assertOk();
        $summary->assertJsonPath('data.assets_on_books', 1)->assertJsonPath('data.cost', '12000.00')->assertJsonPath('data.accumulated_depreciation', '2000.00')->assertJsonPath('data.book_value', '10000.00')
            ->assertJsonPath('data.by_status.draft', 1)->assertJsonPath('data.by_category.0.category.name', 'Lab equipment');

        $forecast = $this->getJson('/api/v1/fixed-assets/forecast?months=3')->assertOk();
        $forecast->assertJsonCount(3, 'data');

        $this->getJson("/api/v1/fixed-assets/{$a}/schedule")->assertOk()->assertJsonCount(12, 'data')->assertJsonPath('summary.book_value', '10000.00')->assertJsonPath('data.0.status', 'posted');
        $this->getJson("/api/v1/fixed-assets/{$a}/schedule?status=planned")->assertJsonCount(10, 'data');
        $this->get('/api/v1/fixed-assets?export=csv')->assertOk()->assertDownload();
    }

    public function test_another_companys_asset_is_not_found(): void
    {
        $foreign = FixedAsset::factory()->create();
        Sanctum::actingAs($this->user('Administrator'));

        $this->getJson("/api/v1/fixed-assets/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/v1/fixed-assets/{$foreign->id}/activate")->assertNotFound();
        $this->putJson("/api/v1/fixed-assets/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/fixed-assets/{$foreign->id}")->assertNotFound();
    }

    public function test_categories_are_managed_and_protected_while_in_use(): void
    {
        $id = $this->postJson('/api/v1/asset-categories', ['name' => 'Vehicles', 'asset_account_code' => '1520', 'duration_periods' => 48, 'method' => 'declining_balance', 'declining_factor' => 0.25])
            ->assertCreated()->assertJsonPath('data.asset_account_code', '1520')->json('data.id');

        $this->postJson('/api/v1/asset-categories', ['name' => 'Vehicles'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson("/api/v1/asset-categories/{$id}", ['duration_periods' => 60])->assertOk()->assertJsonPath('data.duration_periods', 60);
        $this->getJson('/api/v1/asset-categories')->assertOk()->assertJsonCount(2, 'data');

        $this->postJson('/api/v1/fixed-assets', $this->payload(['category_id' => $id]))->assertCreated()->assertJsonPath('data.accounts.asset', '1520')->assertJsonPath('data.method', 'declining_balance');
        Sanctum::actingAs($this->user('Administrator'));
        $this->deleteJson("/api/v1/asset-categories/{$id}")->assertStatus(409);
        $this->deleteJson("/api/v1/asset-categories/{$this->category->id}")->assertNoContent();
    }

    public function test_the_accountant_can_run_assets_but_only_the_owner_can_dispose_of_them(): void
    {
        $id = $this->create();
        $this->postJson("/api/v1/fixed-assets/{$id}/activate")->assertOk();

        $this->postJson("/api/v1/fixed-assets/{$id}/dispose", ['date' => '2026-02-01'])->assertForbidden();

        Sanctum::actingAs($this->user('Owner'));
        $this->postJson("/api/v1/fixed-assets/{$id}/dispose", ['date' => '2026-02-01'])->assertOk();

        Sanctum::actingAs($this->user('Sales Rep'));
        $this->getJson('/api/v1/fixed-assets')->assertForbidden();
    }

    public function test_the_creator_hears_when_someone_else_puts_their_asset_on_the_books(): void
    {
        $id = $this->create();

        Sanctum::actingAs($this->user('Administrator'));
        $this->postJson("/api/v1/fixed-assets/{$id}/activate")->assertOk();

        $note = Notification::query()->where('user_id', $this->accountant->id)->where('link_entity', 'FixedAsset')->latest('id')->first();
        $this->assertSame('Fixed asset approved', $note->title);
        $this->assertSame("/api/v1/fixed-assets/{$id}", $note->link_url);
    }
}
