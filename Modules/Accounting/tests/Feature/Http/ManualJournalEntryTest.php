<?php

namespace Modules\Accounting\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

class ManualJournalEntryTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private Account $cash;

    private Account $capital;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->ensureLedgerAccounts($this->tenant);
        $this->cash = $this->account('1110');
        $this->capital = $this->account('3100');
    }

    public function test_an_accountant_posts_a_balanced_manual_entry(): void
    {
        $this->actingAsRole('Accountant');

        $this->postJson('/api/v1/journal-entries', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.type', 'manual')
            ->assertJsonPath('data.lines_count', 2)
            ->assertJsonPath('data.posted', true);

        $this->assertSame('5000.00', $this->cash->fresh()->balance);
        $this->assertSame('5000.00', $this->capital->fresh()->balance);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'JournalEntry', 'operation' => 'INSERT']);
    }

    public function test_an_unbalanced_or_malformed_entry_is_refused_and_nothing_is_posted(): void
    {
        $this->actingAsRole('Accountant');

        $this->postJson('/api/v1/journal-entries', $this->payload(creditAmount: 4000))->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->postJson('/api/v1/journal-entries', ['entry_date' => '2026-10-01', 'description' => 'x', 'lines' => [['account_id' => $this->cash->id, 'debit' => 5]]])->assertUnprocessable();
        $this->postJson('/api/v1/journal-entries', [
            'entry_date' => '2026-10-01', 'description' => 'both sides',
            'lines' => [['account_id' => $this->cash->id, 'debit' => 5, 'credit' => 5], ['account_id' => $this->capital->id, 'credit' => 0]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.debit');

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_group_headings_and_inactive_accounts_cannot_be_posted_to(): void
    {
        $this->actingAsRole('Accountant');
        $heading = $this->account('1100');
        $this->account('1120')->update(['parent_id' => null, 'active' => false]);
        $this->account('1110')->update(['parent_id' => $heading->id]);
        $this->cash->refresh();
        // 1100 now has a child (1110), so it is a group heading.

        $this->postJson('/api/v1/journal-entries', $this->payload(debitAccount: $heading))->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
        $this->postJson('/api/v1/journal-entries', $this->payload(debitAccount: $this->account('1120')))->assertUnprocessable()->assertJsonValidationErrors('lines.0.account_id');
    }

    public function test_a_manual_entry_is_reversed_by_its_mirror_image_and_only_once(): void
    {
        $this->actingAsRole('Accountant');
        $id = $this->postJson('/api/v1/journal-entries', $this->payload())->json('data.id');

        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['entry_date' => '2026-10-15'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'reversal')
            ->assertJsonPath('data.reversal_of', $id)
            ->assertJsonPath('data.entry_date', '2026-10-15');

        $this->assertSame('0.00', $this->cash->fresh()->balance);
        $this->assertSame('0.00', $this->capital->fresh()->balance);
        $this->assertNotNull(JournalEntry::findOrFail($id)->reversed_at);

        $this->postJson("/api/v1/journal-entries/{$id}/reverse")->assertUnprocessable()->assertJsonPath('message', 'This journal entry has already been reversed.');
    }

    public function test_entries_made_by_a_business_flow_cannot_be_reversed_here(): void
    {
        $this->actingAsRole('Administrator');
        $system = JournalEntry::create(['tenant_id' => $this->tenant->id, 'ref' => 'x', 'description' => 'auto', 'entry_date' => now(), 'posted' => true]);

        $this->postJson("/api/v1/journal-entries/{$system->id}/reverse")->assertUnprocessable();
    }

    public function test_permissions_and_tenancy(): void
    {
        $this->actingAsRole('Sales Manager');
        $this->postJson('/api/v1/journal-entries', $this->payload())->assertForbidden();

        $this->actingAsRole('Auditor');
        $this->postJson('/api/v1/journal-entries', $this->payload())->assertForbidden();

        $this->actingAsRole('Administrator');
        $foreign = JournalEntry::create(['tenant_id' => Tenant::factory()->create()->id, 'type' => 'manual', 'description' => 'x', 'entry_date' => now(), 'posted' => true]);
        $this->postJson("/api/v1/journal-entries/{$foreign->id}/reverse")->assertNotFound();
    }

    public function test_the_connections_map_shows_what_feeds_each_account_and_flags_gaps(): void
    {
        $this->actingAsRole('Accountant');
        Account::factory()->recycle($this->tenant)->create(['code' => '7000', 'name' => 'Custom', 'type' => 'Expense']);
        $this->postJson('/api/v1/journal-entries', $this->payload())->assertCreated();

        $response = $this->getJson('/api/v1/chart-of-accounts/connections')->assertOk();
        $byCode = collect($response->json('data'))->keyBy('code');

        $this->assertSame('header', $byCode['1000']['kind']);
        $this->assertSame('posted', $byCode['1200']['kind']);
        $this->assertContains('Sales invoices', $byCode['1200']['used_by']);
        $this->assertSame('wired but never posted to', $byCode['1200']['attention']);
        $this->assertSame(1, $byCode['1110']['posting_lines']);
        $this->assertNull($byCode['1110']['attention']);
        $this->assertSame('custom', $byCode['7000']['kind']);
        $this->assertSame('custom account with no postings', $byCode['7000']['attention']);
        $this->assertSame('manual', $byCode['1510']['kind']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(float $creditAmount = 5000, ?Account $debitAccount = null): array
    {
        return [
            'entry_date' => '2026-10-01',
            'description' => 'Capital introduced',
            'ref' => 'CAP-1',
            'lines' => [
                ['account_id' => ($debitAccount ?? $this->cash)->id, 'debit' => 5000],
                ['account_id' => $this->capital->id, 'credit' => $creditAmount],
            ],
        ];
    }

    private function account(string $code): Account
    {
        return Account::query()->where('tenant_id', $this->tenant->id)->where('code', $code)->firstOrFail();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
