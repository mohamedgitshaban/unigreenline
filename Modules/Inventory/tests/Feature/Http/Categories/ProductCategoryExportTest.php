<?php

namespace Modules\Inventory\Tests\Feature\Http\Categories;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\ProductCategory;
use Tests\TestCase;

class ProductCategoryExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_manager_can_export_categories_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Manager');
        Sanctum::actingAs($user);

        $category = ProductCategory::factory()->recycle($tenant)->create(['name' => 'Antibiotics']);

        $this->get('/api/v1/categories?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/categories-.*\.csv/', function (GenericExport $export) use ($category) {
            $this->assertSame($category->id, $export->collection()->first()[0]);
            $this->assertSame('Antibiotics', $export->collection()->first()[1]);

            return true;
        });
    }

    public function test_warehouse_employee_without_export_permission_is_forbidden(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Warehouse Employee');
        Sanctum::actingAs($user);

        $this->get('/api/v1/categories?export=csv')->assertForbidden();
    }
}
