<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;

/**
 * Seed users from spec §7, plus accounts for every role the spec's own seed
 * list leaves uncovered (Administrator, Owner, Warehouse Employee,
 * Purchasing, Auditor) — without them there's no way to test those roles'
 * endpoints at all.
 *
 * All seeded users share the password below — force a change on first
 * login outside local/testing environments.
 */
class UserSeeder extends Seeder
{
    private const SEED_PASSWORD = 'password';

    /** @var array<int, array{name: string, email: string, role: string}> */
    private const USERS = [
        ['name' => 'System Administrator', 'email' => 'admin@vetpharma.com', 'role' => 'Administrator'],
        ['name' => 'Ahmed Hassan', 'email' => 'ahmed@vetpharma.com', 'role' => 'Sales Manager'],
        ['name' => 'Mohamed Salem', 'email' => 'msalem@vetpharma.com', 'role' => 'Sales Rep'],
        ['name' => 'Heba Mahmoud', 'email' => 'heba@vetpharma.com', 'role' => 'Sales Rep'],
        ['name' => 'Omar Farouk', 'email' => 'omar@vetpharma.com', 'role' => 'Sales Rep'],
        ['name' => 'Khalid Omar', 'email' => 'khalid@vetpharma.com', 'role' => 'Warehouse Manager'],
        ['name' => 'Rana Sami', 'email' => 'rana@vetpharma.com', 'role' => 'Accountant'],
        ['name' => 'Dina Hassan', 'email' => 'dina@vetpharma.com', 'role' => 'Customer Service'],
        ['name' => 'Youssef Nabil', 'email' => 'youssef@vetpharma.com', 'role' => 'Owner'],
        ['name' => 'Karim Adel', 'email' => 'karim@vetpharma.com', 'role' => 'Warehouse Employee'],
        ['name' => 'Mona Farid', 'email' => 'mona@vetpharma.com', 'role' => 'Purchasing'],
        ['name' => 'Sara Ibrahim', 'email' => 'sara@vetpharma.com', 'role' => 'Auditor'],
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'vetpharma')->firstOrFail();

        foreach (self::USERS as $seed) {
            $user = User::query()->firstOrCreate(
                ['email' => $seed['email']],
                [
                    'tenant_id' => $tenant->id,
                    'name' => $seed['name'],
                    'email_verified_at' => now(),
                    'password' => Hash::make(self::SEED_PASSWORD),
                    'avatar_initials' => $this->initials($seed['name']),
                    'status' => 'active',
                ]
            );

            $user->syncRoles([$seed['role']]);
        }
    }

    private function initials(string $name): string
    {
        $parts = explode(' ', trim($name));

        return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1));
    }
}
