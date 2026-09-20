<?php

namespace Modules\Purchasing\Database\Seeders;

use Illuminate\Database\Seeder;

class PurchasingDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SupplierSeeder::class,
        ]);
    }
}
