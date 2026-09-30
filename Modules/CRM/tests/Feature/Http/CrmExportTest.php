<?php

namespace Modules\CRM\Tests\Feature\Http;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\Complaint;
use Modules\CRM\Models\Customer;
use Modules\CRM\Models\CustomerVisit;
use Modules\CRM\Models\Lead;
use Tests\TestCase;

/**
 * crm.export is only held by Administrator/Owner/Auditor in this build —
 * neither Sales Rep nor Customer Service has it (spec §2's role table
 * omits Export from both rows), so every "can export" case here uses
 * Owner, and every "forbidden" case uses Customer Service, which can
 * still view (crm.view) but not export.
 */
class CrmExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_export_customers_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $customer = Customer::factory()->recycle($tenant)->create(['name' => 'Export Test Clinic']);

        $this->get('/api/v1/customers?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/customers-.*\.csv/', function (GenericExport $export) use ($customer) {
            $this->assertSame($customer->id, $export->collection()->first()[0]);

            return true;
        });
    }

    public function test_customer_service_can_view_but_not_export_customers(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/customers')->assertOk();
        $this->get('/api/v1/customers?export=csv')->assertForbidden();
    }

    public function test_owner_can_export_leads_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $lead = Lead::factory()->recycle($tenant)->create(['name' => 'Export Test Lead']);

        $this->get('/api/v1/leads?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/leads-.*\.csv/', function (GenericExport $export) use ($lead) {
            $this->assertSame($lead->id, $export->collection()->first()[0]);

            return true;
        });
    }

    public function test_customer_service_can_view_but_not_export_leads(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/leads')->assertOk();
        $this->get('/api/v1/leads?export=csv')->assertForbidden();
    }

    public function test_owner_can_export_visits_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $visit = CustomerVisit::factory()->recycle($tenant)->create();

        $this->get('/api/v1/visits?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/visits-.*\.csv/', function (GenericExport $export) use ($visit) {
            $this->assertSame($visit->id, $export->collection()->first()[0]);

            return true;
        });
    }

    public function test_customer_service_can_view_but_not_export_visits(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/visits')->assertOk();
        $this->get('/api/v1/visits?export=csv')->assertForbidden();
    }

    public function test_owner_can_export_complaints_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $complaint = Complaint::factory()->recycle($tenant)->create();

        $this->get('/api/v1/complaints?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/complaints-.*\.csv/', function (GenericExport $export) use ($complaint) {
            $this->assertSame($complaint->id, $export->collection()->first()[0]);

            return true;
        });
    }

    public function test_customer_service_can_view_but_not_export_complaints(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/complaints')->assertOk();
        $this->get('/api/v1/complaints?export=csv')->assertForbidden();
    }

    public function test_owner_can_export_campaigns_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        $campaign = Campaign::factory()->recycle($tenant)->create(['name' => 'Export Test Campaign']);

        $this->get('/api/v1/campaigns?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/campaigns-.*\.csv/', function (GenericExport $export) use ($campaign) {
            $this->assertSame($campaign->id, $export->collection()->first()[0]);

            return true;
        });
    }

    public function test_customer_service_can_view_but_not_export_campaigns(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Customer Service');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/campaigns')->assertOk();
        $this->get('/api/v1/campaigns?export=csv')->assertForbidden();
    }
}
