<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Tenant;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::query()->firstOrCreate(
            ['slug' => 'vetpharma'],
            [
                'name' => 'VetPharma Distribution Co.',
                'plan' => 'enterprise',
                'active' => true,
            ]
        );
    }
}
