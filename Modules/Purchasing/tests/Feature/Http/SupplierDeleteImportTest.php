<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class SupplierDeleteImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_deletes_a_supplier_nobody_has_bought_from(): void
    {
        $this->actingAsRole('Administrator');
        $supplier = Supplier::factory()->recycle($this->tenant)->create();

        $this->deleteJson("/api/v1/suppliers/{$supplier->id}")->assertNoContent();

        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'Supplier', 'entity_id' => $supplier->id, 'operation' => 'DELETE']);
    }

    public function test_a_supplier_with_purchase_orders_cannot_be_deleted(): void
    {
        $this->actingAsRole('Administrator');
        $supplier = Supplier::factory()->recycle($this->tenant)->create();
        PurchaseOrder::factory()->recycle($this->tenant)->create(['supplier_id' => $supplier->id]);

        $this->deleteJson("/api/v1/suppliers/{$supplier->id}")->assertConflict();
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    public function test_purchasing_cannot_delete_until_an_admin_grants_it(): void
    {
        $supplier = Supplier::factory()->recycle($this->tenant)->create();
        $this->actingAsRole('Purchasing');

        $this->deleteJson("/api/v1/suppliers/{$supplier->id}")->assertForbidden();
    }

    public function test_imports_suppliers_with_address_and_updates_matches_on_request(): void
    {
        $this->actingAsRole('Purchasing');
        Supplier::factory()->recycle($this->tenant)->create(['name' => 'Acme Pharma', 'city' => 'Old City']);
        $csv = "Name,Country,City,Address,Currency,Rating\nAcme Pharma,Egypt,Cairo,10 Factory Rd,USD,4\nBeta Labs,Egypt,Giza,2 Lab St,,\n";

        $this->postJson('/api/v1/suppliers/import', ['update_existing' => true, 'file' => UploadedFile::fake()->createWithContent('s.csv', $csv)])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('suppliers', ['name' => 'Acme Pharma', 'city' => 'Cairo', 'address' => '10 Factory Rd', 'currency' => 'USD', 'rating' => 4]);
        $this->assertDatabaseHas('suppliers', ['name' => 'Beta Labs', 'address' => '2 Lab St', 'currency' => 'EGP', 'pay_terms' => 'Net 30']);
    }

    public function test_invalid_supplier_rows_reject_the_file(): void
    {
        $this->actingAsRole('Purchasing');

        $this->postJson('/api/v1/suppliers/import', ['file' => UploadedFile::fake()->createWithContent('s.csv', "Name,Currency,Rating\nOk,EGP,3\nBad,EURO,9\n")])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.row', 3)
            ->assertJsonStructure(['errors' => [['errors' => ['currency', 'rating']]]]);

        $this->assertSame(0, Supplier::query()->count());
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }
}
