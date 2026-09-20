<?php

namespace Modules\CRM\Database\Seeders;

use Illuminate\Database\Seeder;

class CrmDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CustomerSeeder::class,
        ]);
    }
}
