<?php

namespace Modules\Manufacturing\Tests\Unit;

use Modules\Manufacturing\Models\ProductPlanning;
use Modules\Manufacturing\Support\LotSizer;
use PHPUnit\Framework\TestCase;

class LotSizerTest extends TestCase
{
    public function test_without_a_rule_the_shortage_is_rounded_up_to_a_whole_carton(): void
    {
        $this->assertSame([6], LotSizer::lots(5.5, null));
        $this->assertSame([5], LotSizer::lots(5.0, null));
        $this->assertSame([5], LotSizer::lots(5.00001, $this->rule('exact')));   // float noise below a ten-thousandth is ignored
        $this->assertSame([1], LotSizer::lots(0.2, null));
    }

    public function test_a_fixed_lot_is_ordered_in_whole_multiples(): void
    {
        $this->assertSame([25], LotSizer::lots(1, $this->rule('fixed', lot: 25)));
        $this->assertSame([25], LotSizer::lots(25, $this->rule('fixed', lot: 25)));
        $this->assertSame([50], LotSizer::lots(26, $this->rule('fixed', lot: 25)));
    }

    public function test_a_multiple_rounds_up_to_the_next_multiple(): void
    {
        $this->assertSame([12], LotSizer::lots(7, $this->rule('multiple', multiple: 12)));
        $this->assertSame([24], LotSizer::lots(13, $this->rule('multiple', multiple: 12)));
    }

    public function test_minimum_and_maximum_raise_small_orders_and_split_big_ones(): void
    {
        $rule = $this->rule('min_max', min: 20, max: 50);

        $this->assertSame([20], LotSizer::lots(3, $rule));
        $this->assertSame([35], LotSizer::lots(35, $rule));
        $this->assertSame([50, 50, 20], LotSizer::lots(120, $rule));
        $this->assertSame([50, 50], LotSizer::lots(100, $rule));
        // A remainder smaller than the minimum is raised to it.
        $this->assertSame([50, 20], LotSizer::lots(55, $rule));
    }

    private function rule(string $rule, ?int $lot = null, ?int $multiple = null, ?int $min = null, ?int $max = null): ProductPlanning
    {
        return new ProductPlanning(['lot_rule' => $rule, 'lot_qty' => $lot, 'multiple_qty' => $multiple, 'min_qty' => $min, 'max_qty' => $max]);
    }
}
