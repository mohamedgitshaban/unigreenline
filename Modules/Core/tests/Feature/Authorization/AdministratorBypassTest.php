<?php

namespace Modules\Core\Tests\Feature\Authorization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\User;
use Tests\TestCase;

class AdministratorBypassTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_passes_a_gate_check_with_no_matching_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $this->assertTrue(Gate::forUser($admin)->allows('some-ability-nobody-defined'));
    }

    public function test_non_administrator_does_not_bypass_the_gate(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $rep = User::factory()->create();
        $rep->assignRole('Sales Rep');

        $this->assertFalse(Gate::forUser($rep)->allows('some-ability-nobody-defined'));
    }
}
