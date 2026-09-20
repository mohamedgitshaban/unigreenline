<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Accounting\Database\Seeders\AccountingDatabaseSeeder;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\CRM\Database\Seeders\CrmDatabaseSeeder;
use Modules\Inventory\Database\Seeders\InventoryDatabaseSeeder;
use Modules\Purchasing\Database\Seeders\PurchasingDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CoreDatabaseSeeder::class,
            InventoryDatabaseSeeder::class,
            PurchasingDatabaseSeeder::class,
            AccountingDatabaseSeeder::class,
            CrmDatabaseSeeder::class,
        ]);
    }
}
