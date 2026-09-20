<?php

namespace Modules\Inventory\Tests\Feature\Database\Seeders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Database\Seeders\TenantSeeder;
use Modules\Core\Database\Seeders\UserSeeder;
use Modules\Core\Models\User;
use Modules\Inventory\Database\Seeders\WarehouseSeeder;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class WarehouseSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Warehouse::visibleTo() (spec §2 layer 1) hides every warehouse from a
     * non-Owner/Auditor user who isn't in user_warehouses — without this,
     * the seeded Warehouse Employee account could log in but see nothing.
     */
    public function test_the_seeded_warehouse_employee_is_assigned_to_a_warehouse(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(WarehouseSeeder::class);

        $employee = User::query()->where('email', 'karim@vetpharma.com')->firstOrFail();

        $this->assertTrue(
            Warehouse::query()->whereHas('users', fn ($q) => $q->whereKey($employee->id))->exists()
        );
    }

    public function test_the_seeded_warehouse_manager_is_assigned_to_every_warehouse(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(UserSeeder::class);
        $this->seed(WarehouseSeeder::class);

        $manager = User::query()->where('email', 'khalid@vetpharma.com')->firstOrFail();

        $this->assertSame(3, Warehouse::query()->whereHas('users', fn ($q) => $q->whereKey($manager->id))->count());
    }
}
