<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Tenant;
use Modules\Inventory\Models\Unit;

/**
 * The starting packaging unit names for every tenant. Admins add more through
 * POST /units; what each unit holds is set per product (e.g. Package = 10
 * Packets, Carton = 3 Packages).
 */
class UnitSeeder extends Seeder
{
    public const NAMES = ['Packet', 'Package', 'Carton'];

    public function run(): void
    {
        foreach (Tenant::query()->get() as $tenant) {
            foreach (self::NAMES as $name) {
                Unit::query()->firstOrCreate(['tenant_id' => $tenant->id, 'name' => $name]);
            }
        }
    }
}
