<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\User;
use Tests\TestCase;

class TelescopeAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_administrator_can_view_telescope(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');

        $this->assertTrue(Gate::forUser($admin)->allows('viewTelescope'));
    }

    public function test_non_administrator_cannot_view_telescope(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Sales Rep');

        $this->assertFalse(Gate::forUser($user)->allows('viewTelescope'));
    }

    public function test_guest_cannot_view_telescope(): void
    {
        $this->assertFalse(Gate::allows('viewTelescope'));
    }
}
