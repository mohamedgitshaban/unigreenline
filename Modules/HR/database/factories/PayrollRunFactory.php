<?php

namespace Modules\HR\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Tenant;
use Modules\HR\Models\PayrollRun;

/**
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'period' => '2026-09',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'status' => 'draft',
        ];
    }
}
