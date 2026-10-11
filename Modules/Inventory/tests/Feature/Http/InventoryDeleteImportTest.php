<?php

namespace Modules\Inventory\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;
use Modules\Inventory\Models\Warehouse;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class InventoryDeleteImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_deletes_an_unused_category_product_and_warehouse(): void
    {
        $this->actingAsRole('Administrator');
        $category = ProductCategory::factory()->recycle($this->tenant)->create();
        $product = Product::factory()->recycle($this->tenant)->create();
        $warehouse = Warehouse::factory()->recycle($this->tenant)->create();

        $this->deleteJson("/api/v1/categories/{$category->id}")->assertNoContent();
        $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();
        $this->deleteJson("/api/v1/warehouses/{$warehouse->id}")->assertNoContent();

        $this->assertDatabaseMissing('product_categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('warehouses', ['id' => $warehouse->id]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Product', 'entity_id' => $product->id, 'operation' => 'DELETE']);
    }

    public function test_records_still_in_use_cannot_be_deleted(): void
    {
        $this->actingAsRole('Administrator');
        $product = Product::factory()->recycle($this->tenant)->create();
        $warehouse = Warehouse::factory()->recycle($this->tenant)->create();
        InventoryBatch::factory()->recycle([$this->tenant, $product, $warehouse])->create();

        $this->deleteJson("/api/v1/products/{$product->id}")->assertConflict();
        $this->deleteJson("/api/v1/warehouses/{$warehouse->id}")->assertConflict();
        $this->deleteJson("/api/v1/categories/{$product->category_id}")->assertConflict();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_delete_needs_the_delete_permission_and_the_same_tenant(): void
    {
        $product = Product::factory()->recycle($this->tenant)->create();

        $this->actingAsRole('Warehouse Manager');
        $this->deleteJson("/api/v1/products/{$product->id}")->assertForbidden();

        $this->actingAsRole('Administrator');
        $this->deleteJson('/api/v1/products/'.Product::factory()->create()->id)->assertNotFound();
    }

    public function test_imports_products_resolving_category_by_name_and_supplier_by_name(): void
    {
        $this->actingAsRole('Administrator');
        $category = ProductCategory::factory()->recycle($this->tenant)->create(['name' => 'Antibiotics', 'code' => 'ANT']);
        $supplier = Supplier::factory()->recycle($this->tenant)->create(['name' => 'Acme Pharma']);
        $csv = "SKU,Name,Category,Supplier,Pack Unit,Carton Qty,Pack Cost Price,Pack Selling Price,Tax Pct,Active\n"
            ."AMX-1,Amoxicillin,Antibiotics,Acme Pharma,Bottle,24,40,55,14,yes\n"
            ."AMX-2,Doxycycline,ANT,,Vial,12,10.5,15,,no\n";

        $this->postJson('/api/v1/products/import', ['file' => UploadedFile::fake()->createWithContent('products.csv', $csv)])
            ->assertOk()
            ->assertJsonPath('created', 2);

        $this->assertDatabaseHas('products', ['sku' => 'AMX-1', 'category_id' => $category->id, 'supplier_id' => $supplier->id, 'carton_qty' => 24, 'pack_selling_price' => '55.00', 'active' => 1]);
        $this->assertDatabaseHas('products', ['sku' => 'AMX-2', 'category_id' => $category->id, 'supplier_id' => null, 'active' => 0]);
    }

    public function test_products_with_an_unknown_category_or_supplier_are_reported_by_row(): void
    {
        $this->actingAsRole('Administrator');
        ProductCategory::factory()->recycle($this->tenant)->create(['name' => 'Antibiotics']);
        $csv = "SKU,Name,Category,Supplier,Pack Unit,Carton Qty,Pack Cost Price,Pack Selling Price\n"
            ."A-1,Ok,Antibiotics,,Bottle,1,1,2\n"
            ."A-2,No cat,Mystery,,Bottle,1,1,2\n"
            ."A-3,No supplier,Antibiotics,Ghost Co,Bottle,1,1,2\n";

        $this->postJson('/api/v1/products/import', ['file' => UploadedFile::fake()->createWithContent('products.csv', $csv)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.row', 3)
            ->assertJsonPath('errors.0.errors.category.0', 'No product category named or coded "Mystery".')
            ->assertJsonPath('errors.1.row', 4)
            ->assertJsonStructure(['errors' => [1 => ['errors' => ['supplier']]]]);

        $this->assertSame(0, Product::query()->count());
    }

    public function test_imports_warehouses_with_a_manager_by_email(): void
    {
        $this->actingAsRole('Administrator');
        $manager = User::factory()->recycle($this->tenant)->create(['email' => 'boss@example.com', 'name' => 'The Boss']);
        $csv = "Name,City,Governorate,Manager Email,Capacity,Status\nCairo Main,Cairo,Cairo,boss@example.com,5000,active\nAlex Cold,Alexandria,Alexandria,,,maintenance\n";

        $this->postJson('/api/v1/warehouses/import', ['file' => UploadedFile::fake()->createWithContent('w.csv', $csv)])
            ->assertOk()
            ->assertJsonPath('created', 2);

        $this->assertDatabaseHas('warehouses', ['name' => 'Cairo Main', 'manager_id' => $manager->id, 'manager_name' => 'The Boss', 'capacity' => 5000]);
        $this->assertDatabaseHas('warehouses', ['name' => 'Alex Cold', 'status' => 'maintenance']);
    }

    public function test_importing_products_requires_inventory_add(): void
    {
        $this->actingAsRole('Sales Rep');

        $this->postJson('/api/v1/products/import', ['file' => UploadedFile::fake()->createWithContent('p.csv', "SKU\nX\n")])->assertForbidden();
        $this->getJson('/api/v1/products/import-template')->assertForbidden();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
