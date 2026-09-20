<?php

namespace Modules\Purchasing\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Tenant;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Services\ApAgingReportService;
use Tests\TestCase;

class ApAgingReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_suppliers_with_an_outstanding_balance_sorted_by_amount_owed(): void
    {
        $tenant = Tenant::factory()->create();
        $small = Supplier::factory()->recycle($tenant)->create(['balance' => -100]);
        $large = Supplier::factory()->recycle($tenant)->create(['balance' => -900]);

        $report = $this->service()->generate($tenant->id);

        $this->assertSame($large->id, $report['suppliers'][0]['supplier_id']);
        $this->assertSame($small->id, $report['suppliers'][1]['supplier_id']);
        $this->assertSame('1000.00', $report['grand_total_owed']);
    }

    public function test_suppliers_with_a_zero_balance_are_excluded(): void
    {
        $tenant = Tenant::factory()->create();
        Supplier::factory()->recycle($tenant)->create(['balance' => 0]);

        $report = $this->service()->generate($tenant->id);

        $this->assertSame([], $report['suppliers']);
    }

    private function service(): ApAgingReportService
    {
        return $this->app->make(ApAgingReportService::class);
    }
}
