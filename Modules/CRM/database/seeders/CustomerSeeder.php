<?php

namespace Modules\CRM\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\CRM\Models\Customer;

/**
 * Five customers per spec §7 ("mix of A+/A/B classifications, clinic/farm/
 * poultry types, credit limits from 100k to 500k EGP"). Exact prototype
 * figures weren't available to port — these are representative values in
 * that same range, not a byte-for-byte copy.
 */
class CustomerSeeder extends Seeder
{
    private const CUSTOMERS = [
        ['name' => 'Nile Valley Veterinary Clinic', 'type' => 'Clinic', 'classification' => 'A+', 'credit_limit' => 300000, 'rep' => 'ahmed@vetpharma.com'],
        ['name' => 'Delta Dairy Farms', 'type' => 'Farm', 'classification' => 'A', 'credit_limit' => 500000, 'rep' => 'msalem@vetpharma.com'],
        ['name' => 'Fayoum Poultry Co-op', 'type' => 'Poultry', 'classification' => 'A', 'credit_limit' => 250000, 'rep' => 'heba@vetpharma.com'],
        ['name' => 'Cairo Vet Supplies Distributor', 'type' => 'Distributor', 'classification' => 'B', 'credit_limit' => 150000, 'rep' => 'omar@vetpharma.com'],
        ['name' => 'Sohag Camel & Livestock Clinic', 'type' => 'Clinic', 'classification' => 'B', 'credit_limit' => 100000, 'rep' => 'msalem@vetpharma.com'],
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'vetpharma')->firstOrFail();

        foreach (self::CUSTOMERS as $data) {
            $rep = User::query()->where('email', $data['rep'])->first();

            Customer::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $data['name']],
                [
                    'sales_rep_id' => $rep?->id,
                    'type' => $data['type'],
                    'classification' => $data['classification'],
                    'governorate' => 'Cairo',
                    'city' => 'Cairo',
                    'credit_limit' => $data['credit_limit'],
                    'pay_terms' => 'Net 30',
                    'balance' => 0,
                    'status' => 'active',
                ]
            );
        }
    }
}
