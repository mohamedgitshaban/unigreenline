<?php

namespace Modules\Sales\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Services\ArAgingReportService;
use Tests\TestCase;

class ArAgingReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_not_yet_due_is_bucketed_as_current(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        Invoice::factory()->recycle([$tenant, $customer])->create([
            'due_date' => now()->addDays(10), 'balance' => 200, 'status' => 'outstanding',
        ]);

        $report = $this->service()->generate($tenant->id);

        $this->assertSame('200.00', $report['customers'][0]['current']);
        $this->assertSame('0.00', $report['customers'][0]['days_1_30']);
    }

    public function test_invoice_45_days_overdue_lands_in_the_31_60_bucket(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        Invoice::factory()->recycle([$tenant, $customer])->create([
            'due_date' => now()->subDays(45), 'balance' => 300, 'status' => 'overdue',
        ]);

        $report = $this->service()->generate($tenant->id);

        $this->assertSame('300.00', $report['customers'][0]['days_31_60']);
        $this->assertSame('0.00', $report['customers'][0]['days_1_30']);
    }

    public function test_invoice_120_days_overdue_lands_in_the_90_plus_bucket(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        Invoice::factory()->recycle([$tenant, $customer])->create([
            'due_date' => now()->subDays(120), 'balance' => 500, 'status' => 'overdue',
        ]);

        $report = $this->service()->generate($tenant->id);

        $this->assertSame('500.00', $report['customers'][0]['days_90_plus']);
    }

    public function test_paid_invoices_are_excluded(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        Invoice::factory()->recycle([$tenant, $customer])->create(['balance' => 0, 'status' => 'paid']);

        $report = $this->service()->generate($tenant->id);

        $this->assertSame([], $report['customers']);
        $this->assertSame('0.00', $report['grand_total']);
    }

    public function test_multiple_invoices_for_the_same_customer_are_summed(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        Invoice::factory()->recycle([$tenant, $customer])->create(['due_date' => now()->addDays(5), 'balance' => 100, 'status' => 'outstanding']);
        Invoice::factory()->recycle([$tenant, $customer])->create(['due_date' => now()->addDays(5), 'balance' => 50, 'status' => 'partial']);

        $report = $this->service()->generate($tenant->id);

        $this->assertCount(1, $report['customers']);
        $this->assertSame('150.00', $report['customers'][0]['total']);
        $this->assertSame('150.00', $report['grand_total']);
    }

    private function service(): ArAgingReportService
    {
        return $this->app->make(ArAgingReportService::class);
    }
}
