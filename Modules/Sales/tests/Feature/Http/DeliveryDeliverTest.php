<?php

namespace Modules\Sales\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;
use Modules\Sales\Models\Delivery;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class DeliveryDeliverTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_manager_marks_a_pending_delivery_delivered(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $manager = User::factory()->recycle($tenant)->create();
        $manager->assignRole('Sales Manager');
        Sanctum::actingAs($manager);

        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create();
        $delivery = Delivery::factory()->recycle([$tenant, $customer])->for($order, 'salesOrder')->create(['status' => 'pending']);

        $response = $this->putJson("/api/v1/deliveries/{$delivery->id}/deliver");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'delivered');
        $this->assertNotNull($delivery->fresh()->delivered_at);
    }

    public function test_delivering_attaches_the_uploaded_invoice_file(): void
    {
        Storage::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $manager = User::factory()->recycle($tenant)->create();
        $manager->assignRole('Sales Manager');
        Sanctum::actingAs($manager);

        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create();
        $delivery = Delivery::factory()->recycle([$tenant, $customer])->for($order, 'salesOrder')->create(['status' => 'pending']);

        $path = $this->postJson('/api/v1/deliveries/invoices', [
            'invoice' => UploadedFile::fake()->image('signed-invoice.jpg'),
        ])->assertCreated()->json('data.invoice_data');

        $response = $this->putJson("/api/v1/deliveries/{$delivery->id}/deliver", ['invoice_data' => $path]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'delivered');
        $response->assertJsonPath('data.invoice_data', $path);

        $this->get("/api/v1/deliveries/{$delivery->id}/invoice")
            ->assertOk()
            ->assertDownload("delivery-invoice-{$delivery->id}.jpg");
    }

    public function test_delivering_with_a_path_not_from_the_upload_endpoint_is_rejected(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $manager = User::factory()->recycle($tenant)->create();
        $manager->assignRole('Sales Manager');
        Sanctum::actingAs($manager);

        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create();
        $delivery = Delivery::factory()->recycle([$tenant, $customer])->for($order, 'salesOrder')->create(['status' => 'pending']);

        $response = $this->putJson("/api/v1/deliveries/{$delivery->id}/deliver", ['invoice_data' => '../../.env']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('invoice_data');
        $this->assertSame('pending', $delivery->fresh()->status);
    }

    public function test_sales_rep_cannot_deliver_another_reps_order(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $rep = User::factory()->recycle($tenant)->create();
        $rep->assignRole('Sales Rep');
        Sanctum::actingAs($rep);

        $otherRep = User::factory()->recycle($tenant)->create();
        $customer = Customer::factory()->recycle($tenant)->create();
        $order = SalesOrder::factory()->recycle([$tenant, $customer])->create(['sales_rep_id' => $otherRep->id]);
        $delivery = Delivery::factory()->recycle([$tenant, $customer])->for($order, 'salesOrder')->create();

        $response = $this->putJson("/api/v1/deliveries/{$delivery->id}/deliver");

        $response->assertForbidden();
    }
}
