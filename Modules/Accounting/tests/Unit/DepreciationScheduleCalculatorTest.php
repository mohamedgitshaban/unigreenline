<?php

namespace Modules\Accounting\Tests\Unit;

use InvalidArgumentException;
use Modules\Accounting\Support\DepreciationScheduleCalculator;
use PHPUnit\Framework\TestCase;

class DepreciationScheduleCalculatorTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<int, array<string, mixed>>
     */
    private function lines(array $overrides = []): array
    {
        return DepreciationScheduleCalculator::lines($overrides + [
            'original_value' => 12000, 'salvage_value' => 0, 'method' => 'straight_line', 'duration_periods' => 12,
            'period_months' => 1, 'declining_factor' => 0.3, 'prorata' => false, 'start_date' => '2026-01-01',
        ]);
    }

    public function test_straight_line_spreads_the_cost_evenly_and_dates_each_line_on_the_last_day_of_its_period(): void
    {
        $lines = $this->lines();

        $this->assertCount(12, $lines);
        $this->assertSame(1000.0, $lines[0]['amount']);
        $this->assertSame('2026-01-31', $lines[0]['depreciation_date']);
        $this->assertSame('2026-02-28', $lines[1]['depreciation_date']);
        $this->assertSame('2026-12-31', $lines[11]['depreciation_date']);
        $this->assertSame(11000.0, $lines[0]['book_value_after']);
        $this->assertSame(0.0, $lines[11]['book_value_after']);
        $this->assertSame(12000.0, $lines[11]['accumulated_after']);
    }

    public function test_the_salvage_value_is_never_depreciated(): void
    {
        $lines = $this->lines(['original_value' => 10000, 'salvage_value' => 1000, 'duration_periods' => 3]);

        $this->assertSame([3000.0, 3000.0, 3000.0], array_column($lines, 'amount'));
        $this->assertSame(1000.0, end($lines)['book_value_after']);
    }

    public function test_cents_that_do_not_divide_evenly_end_up_on_the_last_line(): void
    {
        $lines = $this->lines(['original_value' => 10000, 'duration_periods' => 3]);

        $this->assertSame([3333.33, 3333.33, 3333.34], array_column($lines, 'amount'));
        $this->assertSame(10000.0, $lines[2]['accumulated_after']);
    }

    public function test_longer_periods_are_dated_on_the_last_day_of_the_period(): void
    {
        $lines = $this->lines(['original_value' => 4000, 'duration_periods' => 4, 'period_months' => 3, 'start_date' => '2026-03-15']);

        $this->assertSame(['2026-05-31', '2026-08-31', '2026-11-30', '2027-02-28'], array_column($lines, 'depreciation_date'));
        $this->assertSame(1000.0, $lines[0]['amount']);
    }

    public function test_prorata_gives_the_first_and_last_part_periods_their_share_by_days(): void
    {
        // From 16 Jan 2026 to 15 Jan 2027: 16 of January's 31 days at the start, 15 of 31 at the end, eleven whole months between.
        $lines = $this->lines(['prorata' => true, 'start_date' => '2026-01-16']);

        $this->assertCount(13, $lines);
        $this->assertSame(516.13, $lines[0]['amount']);
        $this->assertSame(1000.0, $lines[1]['amount']);
        $this->assertSame('2026-01-31', $lines[0]['depreciation_date']);
        $this->assertSame('2026-01-16', $lines[0]['period_start']);
        $this->assertSame('2027-01-15', $lines[12]['depreciation_date']);
        $this->assertSame(483.87, $lines[12]['amount']);
        $this->assertSame(12000.0, $lines[12]['accumulated_after']);
        $this->assertSame(0.0, $lines[12]['book_value_after']);
    }

    public function test_prorata_from_the_first_of_the_month_has_no_part_periods(): void
    {
        $lines = $this->lines(['prorata' => true, 'start_date' => '2026-01-01']);

        $this->assertCount(12, $lines);
        $this->assertSame(array_fill(0, 11, 1000.0), array_slice(array_column($lines, 'amount'), 0, 11));
    }

    public function test_declining_balance_takes_a_share_of_what_is_left_and_the_last_line_clears_the_rest(): void
    {
        $lines = $this->lines(['original_value' => 10000, 'salvage_value' => 1000, 'method' => 'declining_balance', 'declining_factor' => 0.4, 'duration_periods' => 4]);

        $this->assertSame([3600.0, 2160.0, 1296.0, 1944.0], array_column($lines, 'amount'));
        $this->assertSame(1000.0, $lines[3]['book_value_after']);
    }

    public function test_declining_then_straight_switches_when_the_even_spread_is_bigger(): void
    {
        $lines = $this->lines(['original_value' => 10000, 'salvage_value' => 1000, 'method' => 'declining_then_straight', 'declining_factor' => 0.4, 'duration_periods' => 4]);

        $this->assertSame([3600.0, 2160.0, 1620.0, 1620.0], array_column($lines, 'amount'));
        $this->assertSame(9000.0, array_sum(array_column($lines, 'amount')));
    }

    public function test_the_lines_always_add_up_to_the_depreciable_value(): void
    {
        foreach (DepreciationScheduleCalculator::METHODS as $method) {
            foreach ([false, true] as $prorata) {
                $lines = $this->lines(['original_value' => 9999.99, 'salvage_value' => 123.45, 'method' => $method, 'prorata' => $prorata, 'start_date' => '2026-03-10', 'duration_periods' => 7, 'period_months' => 2]);

                $this->assertEqualsWithDelta(9876.54, array_sum(array_column($lines, 'amount')), 0.001, "{$method} prorata=".(int) $prorata);
                $this->assertEqualsWithDelta(123.45, end($lines)['book_value_after'], 0.001);

                foreach ($lines as $line) {
                    $this->assertGreaterThanOrEqual(0.0, $line['amount']);
                }
            }
        }
    }

    public function test_nothing_to_depreciate_gives_no_lines(): void
    {
        $this->assertSame([], $this->lines(['original_value' => 500, 'salvage_value' => 500]));
    }

    public function test_bad_settings_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->lines(['method' => 'magic']);
    }

    public function test_an_asset_needs_at_least_one_period(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->lines(['duration_periods' => 0]);
    }
}
