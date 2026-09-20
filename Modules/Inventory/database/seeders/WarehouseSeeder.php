<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Warehouse;

class WarehouseSeeder extends Seeder
{
    private const WAREHOUSES = [
        ['name' => 'Main Warehouse', 'city' => 'Cairo', 'governorate' => 'Cairo', 'temperature' => 'ambient', 'capacity' => 10000],
        ['name' => 'Cold Storage', 'city' => 'Cairo', 'governorate' => 'Cairo', 'temperature' => 'refrigerated 2-8°C', 'capacity' => 2000],
        ['name' => 'Alexandria Branch', 'city' => 'Alexandria', 'governorate' => 'Alexandria', 'temperature' => 'ambient', 'capacity' => 5000],
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'vetpharma')->firstOrFail();
        $manager = User::query()->where('email', 'khalid@vetpharma.com')->first();
        // Warehouse-scoping (spec §2 layer 1) is enforced by Warehouse::visibleTo() for
        // every non-Owner/Auditor role — without this, the seeded Warehouse Employee
        // account could authenticate but would see zero warehouses.
        $employee = User::query()->where('email', 'karim@vetpharma.com')->first();

        foreach (self::WAREHOUSES as $data) {
            $warehouse = Warehouse::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $data['name']],
                [
                    'city' => $data['city'],
                    'governorate' => $data['governorate'],
                    'manager_id' => $manager?->id,
                    'manager_name' => $manager?->name,
                    'temperature' => $data['temperature'],
                    'capacity' => $data['capacity'],
                    'status' => 'active',
                ]
            );

            if ($manager) {
                $warehouse->users()->syncWithoutDetaching($manager);
            }

            if ($employee && $data['name'] === 'Main Warehouse') {
                $warehouse->users()->syncWithoutDetaching($employee);
            }
        }
    }
}
