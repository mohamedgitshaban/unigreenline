<?php

namespace Modules\Purchasing\Tests\Feature\Http;

use App\Exports\GenericExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class SupplierExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_can_export_suppliers_as_csv(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        // Accountant reaches this via accounting.export, not purchasing.export
        // (spec §2: Accountant has no purchasing.* at all) — same dual-permission
        // reasoning as SupplierPolicy::viewAny.
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Accountant');
        Sanctum::actingAs($user);

        $supplier = Supplier::factory()->recycle($tenant)->create(['name' => 'Export Test Supplier']);

        $this->get('/api/v1/suppliers?export=csv')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/suppliers-.*\.csv/', function (GenericExport $export) use ($supplier) {
            $this->assertSame($supplier->id, $export->collection()->first()[0]);
            $this->assertSame('Export Test Supplier', $export->collection()->first()[1]);

            return true;
        });
    }

    /**
     * Purchasing role can view suppliers (purchasing.view) but has no
     * Export capability on any module per spec §2's role table.
     */
    public function test_purchasing_role_can_view_but_not_export_suppliers(): void
    {
        Excel::fake();
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Purchasing');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/suppliers')->assertOk();
        $this->get('/api/v1/suppliers?export=csv')->assertForbidden();
    }
}
