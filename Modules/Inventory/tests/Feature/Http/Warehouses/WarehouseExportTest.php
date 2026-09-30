<?php

namespace Modules\Inventory\Tests\Feature\Http\Warehouses;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class WarehouseExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_manager_can_export_warehouses_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $warehouse = Warehouse::factory()->recycle($tenant)->create(['name' => 'Main Warehouse']);
        $warehouse->users()->attach($user);

        $response = $this->get('/api/v1/warehouses?export=csv');

        $response->assertOk();
        Excel::matchByRegex();
        Excel::assertDownloaded('/warehouses-.*\.csv/', function (GenericExport $export) use ($warehouse) {
            $this->assertSame(
                ['ID', 'Name', 'City', 'Governorate', 'Temperature', 'Capacity', 'Status', 'Stock Value'],
                $export->headings()
            );
            $this->assertSame($warehouse->id, $export->collection()->first()[0]);
            $this->assertSame('Main Warehouse', $export->collection()->first()[1]);

            return true;
        });
    }

    public function test_export_only_includes_rows_the_json_endpoint_would_also_return(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $assigned = Warehouse::factory()->recycle($tenant)->create(['name' => 'Assigned WH']);
        $assigned->users()->attach($user);
        Warehouse::factory()->recycle($tenant)->create(['name' => 'Unassigned WH']);

        $this->get('/api/v1/warehouses?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/warehouses-.*\.csv/', function (GenericExport $export) {
            $this->assertCount(1, $export->collection());

            return true;
        });
    }

    public function test_can_export_as_xlsx(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);
        Warehouse::factory()->recycle($tenant)->create();

        $this->get('/api/v1/warehouses?export=xlsx')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/warehouses-.*\.xlsx/');
    }

    public function test_warehouse_employee_without_export_permission_is_forbidden(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        // Warehouse Employee has inventory.view/add/edit but not inventory.export (spec §2).
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Employee');
        Sanctum::actingAs($user);

        $response = $this->get('/api/v1/warehouses?export=csv');

        $response->assertForbidden();
    }

    public function test_unrecognized_export_value_falls_back_to_the_normal_json_response(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);
        Warehouse::factory()->recycle($tenant)->create();

        $response = $this->getJson('/api/v1/warehouses?export=pdf');

        $response->assertOk();
        $response->assertJsonStructure(['data']);
    }
}
