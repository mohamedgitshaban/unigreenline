<?php

namespace Modules\Accounting\Database\Seeders;

use Illuminate\Database\Seeder;

class AccountingDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AccountSeeder::class,
        ]);
    }
}
