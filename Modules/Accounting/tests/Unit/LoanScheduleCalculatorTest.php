<?php

namespace Modules\Accounting\Tests\Unit;

use InvalidArgumentException;
use Modules\Accounting\Support\LoanScheduleCalculator;
use PHPUnit\Framework\TestCase;

class LoanScheduleCalculatorTest extends TestCase
{
    public function test_an_annuity_has_a_level_payment_with_falling_interest(): void
    {
        $rows = LoanScheduleCalculator::schedule(10000, 12, 'annuity', 12, 1, '2026-02-01');

        $this->assertCount(12, $rows);
        $this->assertSame(888.49, $rows[0]['payment_amount']);
        $this->assertSame(100.0, $rows[0]['interest_amount']);
        $this->assertSame(788.49, $rows[0]['principal_amount']);
        $this->assertSame(9211.51, $rows[0]['closing_balance']);
        $this->assertSame(92.12, $rows[1]['interest_amount']);
        $this->assertSame('2026-02-01', $rows[0]['due_date']);
        $this->assertSame('2027-01-01', $rows[11]['due_date']);
        $this->assertSame(0.0, $rows[11]['closing_balance']);
        $this->assertEqualsWithDelta(10000, array_sum(array_column($rows, 'principal_amount')), 0.001);
        $this->assertEqualsWithDelta(661.85, array_sum(array_column($rows, 'interest_amount')), 0.2);
    }

    public function test_the_last_instalment_absorbs_the_rounding(): void
    {
        $rows = LoanScheduleCalculator::schedule(10000, 12, 'annuity', 12, 1, '2026-02-01');

        $this->assertEqualsWithDelta(888.49, $rows[10]['payment_amount'], 0.0001);
        $this->assertEqualsWithDelta(888.49, $rows[11]['payment_amount'], 0.2);
        $this->assertSame($rows[11]['opening_balance'], $rows[11]['principal_amount']);
    }

    public function test_fixed_principal_repays_the_same_principal_each_period(): void
    {
        $rows = LoanScheduleCalculator::schedule(12000, 12, 'fixed_principal', 12, 1, '2026-02-01');

        $this->assertSame(array_fill(0, 12, 1000.0), array_column($rows, 'principal_amount'));
        $this->assertSame(120.0, $rows[0]['interest_amount']);
        $this->assertSame(1120.0, $rows[0]['payment_amount']);
        $this->assertSame(10.0, $rows[11]['interest_amount']);
        $this->assertSame(780.0, array_sum(array_column($rows, 'interest_amount')));
    }

    public function test_interest_only_repays_the_principal_with_the_last_instalment(): void
    {
        $rows = LoanScheduleCalculator::schedule(10000, 6, 'interest_only', 4, 3, '2026-04-01');

        $this->assertSame([150.0, 150.0, 150.0, 150.0], array_column($rows, 'interest_amount'));
        $this->assertSame([0.0, 0.0, 0.0, 10000.0], array_column($rows, 'principal_amount'));
        $this->assertSame(10150.0, $rows[3]['payment_amount']);
        $this->assertSame(['2026-04-01', '2026-07-01', '2026-10-01', '2027-01-01'], array_column($rows, 'due_date'));
    }

    public function test_a_grace_period_pays_interest_only_first(): void
    {
        $rows = LoanScheduleCalculator::schedule(12000, 12, 'fixed_principal', 6, 1, '2026-02-01', 2);

        $this->assertSame([0.0, 0.0, 3000.0, 3000.0, 3000.0, 3000.0], array_column($rows, 'principal_amount'));
        $this->assertSame([120.0, 120.0, 120.0, 90.0, 60.0, 30.0], array_column($rows, 'interest_amount'));
    }

    public function test_a_loan_without_interest_divides_evenly(): void
    {
        $rows = LoanScheduleCalculator::schedule(1200, 0, 'annuity', 4, 1, '2026-02-01');

        $this->assertSame([300.0, 300.0, 300.0, 300.0], array_column($rows, 'payment_amount'));
        $this->assertSame(0.0, array_sum(array_column($rows, 'interest_amount')));
    }

    public function test_instalments_can_be_numbered_from_a_later_sequence(): void
    {
        $rows = LoanScheduleCalculator::schedule(1000, 0, 'annuity', 2, 1, '2026-02-01', 0, 7);

        $this->assertSame([7, 8], array_column($rows, 'sequence'));
    }

    public function test_how_many_periods_a_payment_needs_is_the_inverse_of_the_payment(): void
    {
        $rate = LoanScheduleCalculator::periodRate(12, 1);

        $this->assertSame(0.01, $rate);
        $this->assertSame(12, LoanScheduleCalculator::periodsFor(10000, $rate, 888.49));
        $this->assertSame(3, LoanScheduleCalculator::periodsFor(900, $rate, 888.49 - 2.0 > 0 ? 400 : 1), 'a smaller balance on the same payment ends sooner');
        $this->assertSame(4, LoanScheduleCalculator::periodsFor(1000, 0.0, 300));
    }

    public function test_a_payment_that_does_not_cover_the_interest_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LoanScheduleCalculator::periodsFor(10000, 0.01, 50);
    }

    public function test_grace_must_be_shorter_than_the_loan(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LoanScheduleCalculator::schedule(1000, 5, 'annuity', 3, 1, '2026-02-01', 3);
    }

    public function test_an_unknown_method_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LoanScheduleCalculator::schedule(1000, 5, 'balloon', 3, 1, '2026-02-01');
    }
}
