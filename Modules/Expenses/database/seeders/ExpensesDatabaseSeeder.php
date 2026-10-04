<?php

namespace Modules\Expenses\Database\Seeders;

use Illuminate\Database\Seeder;

class ExpensesDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ExpenseCategorySeeder::class,
        ]);
    }
}
