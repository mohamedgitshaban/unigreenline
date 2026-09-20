<?php

namespace Modules\Sales\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Tenant;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Invoice;
use Tests\TestCase;

class FlipOverdueInvoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_flips_a_past_due_outstanding_invoice_to_overdue(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create([
            'status' => 'outstanding', 'due_date' => now()->subDays(5),
        ]);

        $this->artisan('invoices:flip-overdue')->assertExitCode(0);

        $this->assertSame('overdue', $invoice->fresh()->status);
    }

    public function test_does_not_touch_an_invoice_not_yet_due(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create([
            'status' => 'outstanding', 'due_date' => now()->addDays(5),
        ]);

        $this->artisan('invoices:flip-overdue');

        $this->assertSame('outstanding', $invoice->fresh()->status);
    }

    public function test_does_not_touch_an_already_paid_invoice(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create([
            'status' => 'paid', 'due_date' => now()->subDays(5),
        ]);

        $this->artisan('invoices:flip-overdue');

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_creates_a_broadcast_notification_for_each_flipped_invoice(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create([
            'status' => 'partial', 'due_date' => now()->subDays(1),
        ]);

        $this->artisan('invoices:flip-overdue');

        $this->assertDatabaseHas('notifications', [
            'type' => 'invoice_overdue',
            'link_module' => 'sales',
            'link_id' => $invoice->id,
            'user_id' => null,
        ]);
    }

    public function test_records_an_audit_log_entry(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        $invoice = Invoice::factory()->recycle([$tenant, $customer])->create([
            'status' => 'outstanding', 'due_date' => now()->subDays(5),
        ]);

        $this->artisan('invoices:flip-overdue');

        $this->assertDatabaseHas('audit_log', [
            'module' => 'sales',
            'entity_type' => 'Invoice',
            'entity_id' => $invoice->id,
            'operation' => 'UPDATE',
        ]);
    }
}
