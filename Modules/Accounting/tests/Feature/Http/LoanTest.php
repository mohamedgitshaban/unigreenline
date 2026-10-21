<?php

namespace Modules\Accounting\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Loan;
use Modules\Accounting\Models\LoanInstallment;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Notification;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\Concerns\EnsuresLedgerAccounts;
use Tests\TestCase;

class LoanTest extends TestCase
{
    use EnsuresLedgerAccounts, RefreshDatabase;

    private Tenant $tenant;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $this->ensureLedgerAccounts($this->tenant);
        $this->accountant = $this->user('Accountant');
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
    private function terms(array $overrides = []): array
    {
        return $overrides + ['name' => 'Bank loan', 'principal' => 12000, 'annual_rate_pct' => 12, 'periods' => 12, 'start_date' => '2026-01-01', 'counterparty_name' => 'National Bank'];
    }

    private function agree(array $overrides = []): string
    {
        return $this->postJson('/api/v1/loans', $this->terms($overrides))->assertCreated()->json('data.id');
    }

    public function test_a_loan_is_agreed_as_a_draft_with_its_repayment_schedule(): void
    {
        $response = $this->postJson('/api/v1/loans', $this->terms())->assertCreated();

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', 'LN00001')
            ->assertJsonPath('data.kind', 'borrowed')
            ->assertJsonPath('data.method', 'annuity')
            ->assertJsonPath('data.first_due_date', '2026-02-01')
            ->assertJsonPath('data.accounts.principal', '2400')
            ->assertJsonPath('data.accounts.interest', '6600')
            ->assertJsonPath('data.installments_count', 12)
            ->assertJsonPath('data.installments_paid', 0)
            ->assertJsonPath('data.outstanding_principal', '0.00')
            ->assertJsonPath('data.next_installment.sequence', 1)
            ->assertJsonPath('data.counterparty.name', 'National Bank');

        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Loan', 'operation' => 'INSERT']);
        $this->assertSame('LN00002', $this->postJson('/api/v1/loans', $this->terms())->json('data.number'));
    }

    public function test_the_schedule_shows_interest_principal_and_balance_for_every_instalment(): void
    {
        $id = $this->agree(['principal' => 10000]);

        $schedule = $this->getJson("/api/v1/loans/{$id}/schedule")->assertOk();

        $schedule->assertJsonCount(12, 'data')
            ->assertJsonPath('data.0.payment_amount', '888.49')
            ->assertJsonPath('data.0.interest_amount', '100.00')
            ->assertJsonPath('data.0.principal_amount', '788.49')
            ->assertJsonPath('data.0.closing_balance', '9211.51')
            ->assertJsonPath('data.11.closing_balance', '0.00');
        $this->assertEqualsWithDelta(661.85, (float) $schedule->json('summary.total_interest'), 0.2);
    }

    public function test_a_schedule_can_be_previewed_before_agreeing_anything(): void
    {
        $this->postJson('/api/v1/loans/preview', ['principal' => 10000, 'annual_rate_pct' => 6, 'periods' => 4, 'period_months' => 3, 'method' => 'interest_only', 'start_date' => '2026-01-01'])
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.3.payment_amount', 10150)
            ->assertJsonPath('summary.total_interest', '600.00')
            ->assertJsonPath('summary.first_payment', 150);

        $this->assertSame(0, Loan::query()->count());
    }

    public function test_the_terms_are_validated(): void
    {
        $this->postJson('/api/v1/loans', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'principal', 'periods', 'start_date']);
        $this->postJson('/api/v1/loans', $this->terms(['method' => 'balloon', 'period_months' => 5]))->assertUnprocessable()->assertJsonValidationErrors(['method', 'period_months']);
        $this->postJson('/api/v1/loans', $this->terms(['grace_periods' => 12]))->assertUnprocessable()->assertJsonValidationErrors('grace_periods');
        $this->postJson('/api/v1/loans', $this->terms(['first_due_date' => '2025-12-01']))->assertUnprocessable()->assertJsonValidationErrors('first_due_date');
        $this->postJson('/api/v1/loans', $this->terms(['interest_account_code' => '9999']))->assertUnprocessable()->assertJsonValidationErrors('interest_account_code');
    }

    public function test_activating_a_borrowed_loan_brings_the_money_in_and_records_the_debt(): void
    {
        $id = $this->agree();

        $this->postJson("/api/v1/loans/{$id}/activate", ['account_code' => '1120'])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.outstanding_principal', '12000.00')->assertJsonPath('data.accounts.disbursement', '1120');

        $this->assertEquals(12000, $this->balance('1120'));
        $this->assertEquals(12000, $this->balance('2400'));
        $this->postJson("/api/v1/loans/{$id}/activate", ['account_code' => '1120'])->assertUnprocessable();
        $this->putJson("/api/v1/loans/{$id}", ['principal' => 1])->assertUnprocessable();
    }

    public function test_paying_an_instalment_books_interest_and_principal_and_must_follow_the_order(): void
    {
        $id = $this->agree(['principal' => 10000]);
        $this->postJson("/api/v1/loans/{$id}/activate", ['account_code' => '1120'])->assertOk();

        $this->postJson("/api/v1/loans/{$id}/installments/2/pay")->assertUnprocessable()->assertJsonPath('message', 'Earlier instalments have to be paid first.');
        $this->postJson("/api/v1/loans/{$id}/installments/1/pay", ['payment_date' => '2026-02-01'])
            ->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonPath('data.paid_on', '2026-02-01');

        $this->assertEquals(100, $this->balance('6600'));
        $this->assertEquals(10000 - 788.49, $this->balance('2400'));
        $this->assertEquals(10000 - 888.49, $this->balance('1120'));
        $this->getJson("/api/v1/loans/{$id}")->assertJsonPath('data.outstanding_principal', '9211.51')->assertJsonPath('data.interest_paid', '100.00')->assertJsonPath('data.installments_paid', 1)->assertJsonPath('data.next_installment.sequence', 2);

        $this->postJson("/api/v1/loans/{$id}/installments/1/pay")->assertUnprocessable()->assertJsonPath('message', 'This instalment has already been paid.');
        $this->postJson("/api/v1/loans/{$id}/installments/99/pay")->assertUnprocessable();
    }

    public function test_paying_the_last_instalment_closes_the_loan_and_the_books_balance_to_nothing_owed(): void
    {
        $id = $this->agree(['principal' => 3000, 'periods' => 3, 'annual_rate_pct' => 0]);
        $this->postJson("/api/v1/loans/{$id}/activate", ['account_code' => '1120'])->assertOk();

        foreach ([1, 2, 3] as $sequence) {
            $this->postJson("/api/v1/loans/{$id}/installments/{$sequence}/pay")->assertOk();
        }

        $this->getJson("/api/v1/loans/{$id}")->assertJsonPath('data.status', 'closed')->assertJsonPath('data.outstanding_principal', '0.00')->assertJsonPath('data.next_installment', null);
        $this->assertEquals(0, $this->balance('2400'));
        $this->assertEquals(0, $this->balance('1120'));
        $this->postJson("/api/v1/loans/{$id}/installments/3/pay")->assertUnprocessable();
    }

    public function test_a_loan_we_granted_runs_the_other_way(): void
    {
        $id = $this->agree(['kind' => 'granted', 'name' => 'Staff advance', 'principal' => 3000, 'periods' => 3, 'annual_rate_pct' => 12, 'counterparty_name' => 'Sara']);

        $this->getJson("/api/v1/loans/{$id}")->assertJsonPath('data.accounts.principal', '1600')->assertJsonPath('data.accounts.interest', '4300');
        $this->postJson("/api/v1/loans/{$id}/activate", ['account_code' => '1110'])->assertOk();
        $this->assertEquals(3000, $this->balance('1600'));
        $this->assertEquals(-3000, $this->balance('1110'));

        $installment = $this->postJson("/api/v1/loans/{$id}/installments/1/pay")->assertOk()->json('data');

        $this->assertEqualsWithDelta(3000 - (float) $installment['principal_amount'], $this->balance('1600'), 0.001, 'what is still owed to us');
        $this->assertEqualsWithDelta((float) $installment['interest_amount'], $this->balance('4300'), 0.001, 'interest income');
        $this->assertEqualsWithDelta(-3000 + (float) $installment['payment_amount'], $this->balance('1110'), 0.001, 'cash went out then came back');
    }

    public function test_a_partial_early_repayment_can_shorten_the_loan_keeping_the_instalment(): void
    {
        $id = $this->agree(['principal' => 10000]);
        $this->postJson("/api/v1/loans/{$id}/activate", ['account_code' => '1120'])->assertOk();
        $this->postJson("/api/v1/loans/{$id}/installments/1/pay")->assertOk();

        Sanctum::actingAs($this->user('Owner'));
        $this->postJson("/api/v1/loans/{$id}/early-repayment", ['mode' => 'reduce_term', 'amount' => 3000, 'payment_account_code' => '1120'])
            ->assertOk()->assertJsonPath('data.outstanding_principal', '6211.51')->assertJsonPath('data.status', 'active');

        $left = LoanInstallment::query()->where('loan_id', $id)->where('status', 'planned')->orderBy('sequence')->get();
        $this->assertSame(2, $left->first()->sequence);
        $this->assertLessThan(11, $left->count(), 'the loan ends sooner');
        $this->assertEqualsWithDelta(888.49, (float) $left->first()->payment_amount, 0.001, 'the instalment stays');
        $this->assertLessThanOrEqual(888.49 + 0.001, (float) $left->last()->payment_amount, 'only the last one is smaller');
        $this->assertEquals(0, (float) $left->last()->closing_balance);
        $this->assertEqualsWithDelta(6211.51, (float) $left->sum('principal_amount'), 0.001);
        $this->assertEquals(10000 - 788.49 - 3000, $this->balance('2400'));
    }

    public function test_a_partial_early_repayment_can_lower_the_instalment_keeping_the_end_date(): void
    {
        $id = $this->agree(['principal' => 10000]);
        $this->postJson("/api/v1/loans/{$id}/activate", ['account_code' => '1120'])->assertOk();

        Sanctum::actingAs($this->user('Owner'));
        $this->postJson("/api/v1/loans/{$id}/early-repayment", ['mode' => 'reduce_installment', 'amount' => 5000, 'payment_account_code' => '1120'])->assertOk();

        $left = LoanInstallment::query()->where('loan_id', $id)->where('status', 'planned')->orderBy('sequence')->get();
        $this->assertCount(12, $left);
        $this->assertLessThan(500, (float) $left->first()->payment_amount);
        $this->assertSame('2026-02-01', $left->first()->due_date->toDateString());
        $this->assertSame('2027-01-01', $left->last()->due_date->toDateString());
    }

    public function test_paying_the_loan_off_early_clears_the_debt_with_the_interest_run_up_since_the_last_instalment(): void
    {
        $id = $this->agree(['principal' => 10000]);
        $this->postJson("/api/v1/loans/{$id}/activate", ['account_code' => '1120'])->assertOk();
        $this->postJson("/api/v1/loans/{$id}/installments/1/pay", ['payment_date' => '2026-02-01'])->assertOk();

        Sanctum::actingAs($this->user('Owner'));
        $this->postJson("/api/v1/loans/{$id}/early-repayment", ['mode' => 'payoff', 'date' => '2026-02-16', 'payment_account_code' => '1120'])
            ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.outstanding_principal', '0.00');

        $accrued = round(9211.51 * 0.12 * 15 / 365, 2);
        $this->assertEquals(0, $this->balance('2400'));
        $this->assertEquals(round(100 + $accrued, 2), round($this->balance('6600'), 2));
        $this->assertSame(0, LoanInstallment::query()->where('loan_id', $id)->where('status', 'planned')->count());
    }

    public function test_early_repayment_is_checked(): void
    {
        $id = $this->agree();
        Sanctum::actingAs($this->user('Owner'));

        $this->postJson("/api/v1/loans/{$id}/early-repayment", ['mode' => 'payoff', 'payment_account_code' => '1120'])->assertUnprocessable()->assertJsonPath('message', 'Only an active loan can be repaid early.');

        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/loans/{$id}/activate", ['account_code' => '1120'])->assertOk();
        Sanctum::actingAs($this->user('Owner'));
        $this->postJson("/api/v1/loans/{$id}/early-repayment", ['mode' => 'reduce_term'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/loans/{$id}/early-repayment", ['mode' => 'reduce_term', 'amount' => 12000])->assertUnprocessable();
        $this->postJson("/api/v1/loans/{$id}/early-repayment", ['mode' => 'sideways', 'amount' => 100])->assertUnprocessable()->assertJsonValidationErrors('mode');

        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/loans/{$id}/early-repayment", ['mode' => 'payoff'])->assertForbidden();
    }

    public function test_only_a_draft_can_be_changed_cancelled_or_deleted(): void
    {
        $id = $this->agree();

        $this->putJson("/api/v1/loans/{$id}", ['periods' => 6, 'principal' => 6000])->assertOk()->assertJsonPath('data.installments_count', 6)->assertJsonPath('data.principal', '6000.00');
        $this->putJson("/api/v1/loans/{$id}", ['kind' => 'granted'])->assertOk()->assertJsonPath('data.accounts.principal', '1600');
        $this->postJson("/api/v1/loans/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $active = $this->agree(['name' => 'Active']);
        $this->postJson("/api/v1/loans/{$active}/activate", ['account_code' => '1120'])->assertOk();
        $this->postJson("/api/v1/loans/{$active}/cancel")->assertUnprocessable();
        $this->putJson("/api/v1/loans/{$active}", ['notes' => 'Renegotiated'])->assertOk()->assertJsonPath('data.notes', 'Renegotiated');

        Sanctum::actingAs($this->user('Administrator'));
        $this->deleteJson("/api/v1/loans/{$active}")->assertUnprocessable();
        $this->deleteJson("/api/v1/loans/{$id}")->assertNoContent();
    }

    public function test_the_summary_and_the_due_list_show_what_is_coming_and_what_is_late(): void
    {
        $id = $this->agree(['principal' => 1200, 'periods' => 12, 'annual_rate_pct' => 0, 'start_date' => now()->subMonths(2)->toDateString(), 'first_due_date' => now()->subMonth()->toDateString()]);
        $this->postJson("/api/v1/loans/{$id}/activate", ['account_code' => '1120'])->assertOk();

        $summary = $this->getJson('/api/v1/loans/summary')->assertOk();
        $summary->assertJsonPath('data.by_kind.borrowed.active_loans', 1)->assertJsonPath('data.by_kind.borrowed.outstanding_principal', '1200.00')
            ->assertJsonPath('data.by_kind.granted.active_loans', 0)
            ->assertJsonPath('data.overdue.installments', 1)->assertJsonPath('data.overdue.amount', '100.00')
            ->assertJsonPath('data.due_next_30_days.installments', 1);

        $due = $this->getJson('/api/v1/loans/due?days=10')->assertOk();
        $due->assertJsonCount(2, 'data')->assertJsonPath('data.0.overdue', true)->assertJsonPath('data.0.loan.number', 'LN00001')->assertJsonPath('data.1.overdue', false);
        $this->getJson('/api/v1/loans/due?days=5&kind=granted')->assertJsonCount(0, 'data');
    }

    public function test_loans_can_be_listed_filtered_and_exported(): void
    {
        $this->agree(['name' => 'Alpha']);
        $this->agree(['name' => 'Beta', 'kind' => 'granted']);

        $this->getJson('/api/v1/loans')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/loans?kind=granted')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Beta');
        $this->getJson('/api/v1/loans?search=alph')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/loans?status=active')->assertJsonCount(0, 'data');
        $this->get('/api/v1/loans?export=csv')->assertOk()->assertDownload();
    }

    public function test_another_companys_loan_is_not_found(): void
    {
        $foreign = Loan::factory()->create();
        Sanctum::actingAs($this->user('Administrator'));

        $this->getJson("/api/v1/loans/{$foreign->id}")->assertNotFound();
        $this->postJson("/api/v1/loans/{$foreign->id}/activate", ['account_code' => '1120'])->assertNotFound();
        $this->putJson("/api/v1/loans/{$foreign->id}", ['name' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/loans/{$foreign->id}")->assertNotFound();
    }

    public function test_reminders_tell_the_people_who_pay_loans_once_a_day(): void
    {
        $id = $this->agree(['principal' => 1200, 'periods' => 12, 'annual_rate_pct' => 0, 'start_date' => now()->subMonths(2)->toDateString(), 'first_due_date' => now()->subMonth()->toDateString()]);
        $this->postJson("/api/v1/loans/{$id}/activate", ['account_code' => '1120'])->assertOk();
        $clerk = $this->user('Sales Rep');

        $this->artisan('loans:remind')->assertSuccessful();

        $notes = Notification::query()->where('link_entity', 'Loan')->where('type', 'loan.overdue')->get();
        $this->assertSame([$this->accountant->id], $notes->pluck('user_id')->all());
        $this->assertSame("/api/v1/loans/{$id}", $notes->first()->link_url);
        $this->assertStringContainsString('already late', $notes->first()->body);
        $this->assertSame(0, Notification::query()->where('user_id', $clerk->id)->where('link_entity', 'Loan')->count());

        $this->artisan('loans:remind')->assertSuccessful();
        $this->assertSame(1, Notification::query()->where('type', 'loan.overdue')->count(), 'only once a day');
    }

    public function test_the_accountant_runs_loans_but_a_sales_rep_cannot_see_them(): void
    {
        $this->getJson('/api/v1/loans')->assertOk();

        Sanctum::actingAs($this->user('Sales Rep'));
        $this->getJson('/api/v1/loans')->assertForbidden();
        $this->postJson('/api/v1/loans', $this->terms())->assertForbidden();
    }

    public function test_the_accounting_dashboard_includes_assets_and_loans(): void
    {
        $id = $this->agree();
        $this->postJson("/api/v1/loans/{$id}/activate", ['account_code' => '1120'])->assertOk();

        $data = $this->getJson('/api/v1/dashboards/accounting')->assertOk()->json('data');
        $kpis = collect($data['kpis'])->keyBy('key');

        $this->assertEquals(12000, $kpis['loans_borrowed']['value']);
        $this->assertArrayHasKey('assets_on_books', $kpis->all());
        $this->assertContains('loans', collect($data['features'])->pluck('key')->all());
    }
}
