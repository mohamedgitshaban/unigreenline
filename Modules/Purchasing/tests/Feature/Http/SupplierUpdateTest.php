<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class SupplierUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
    }

    public function test_purchasing_role_updates_a_supplier(): void
    {
        $this->actingAsRole('Purchasing');
        $supplier = Supplier::factory()->recycle($this->tenant)->create(['name' => 'EgyVet', 'rating' => 3]);

        $response = $this->putJson("/api/v1/suppliers/{$supplier->id}", ['contact' => 'Ahmed Hassan', 'rating' => 5, 'status' => 'inactive']);

        $response->assertOk();
        $response->assertJsonPath('data.contact', 'Ahmed Hassan');
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id, 'name' => 'EgyVet', 'rating' => 5, 'status' => 'inactive']);
    }

    public function test_balance_cannot_be_changed(): void
    {
        $this->actingAsRole('Purchasing');
        $supplier = Supplier::factory()->recycle($this->tenant)->create();

        $this->putJson("/api/v1/suppliers/{$supplier->id}", ['balance' => -5000])->assertOk();

        $this->assertSame('0.00', $supplier->fresh()->balance);
    }

    public function test_renaming_to_another_suppliers_name_is_rejected(): void
    {
        $this->actingAsRole('Purchasing');
        Supplier::factory()->recycle($this->tenant)->create(['name' => 'ArabiVet']);
        $supplier = Supplier::factory()->recycle($this->tenant)->create(['name' => 'EgyVet']);

        $this->putJson("/api/v1/suppliers/{$supplier->id}", ['name' => 'EgyVet'])->assertOk();

        $response = $this->putJson("/api/v1/suppliers/{$supplier->id}", ['name' => 'ArabiVet']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_user_without_purchasing_edit_is_forbidden(): void
    {
        $this->actingAsRole('Accountant');
        $supplier = Supplier::factory()->recycle($this->tenant)->create();

        $this->putJson("/api/v1/suppliers/{$supplier->id}", ['name' => 'Renamed'])->assertForbidden();
    }

    public function test_supplier_from_another_tenant_is_forbidden(): void
    {
        $this->actingAsRole('Purchasing');
        $otherSupplier = Supplier::factory()->recycle(Tenant::factory()->create())->create(['name' => 'EgyVet']);

        $this->putJson("/api/v1/suppliers/{$otherSupplier->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->assertDatabaseHas('suppliers', ['id' => $otherSupplier->id, 'name' => 'EgyVet']);
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);
    }
}
