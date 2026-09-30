<?php

namespace Modules\Core\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * Covers the shared Sortable trait through one representative index
 * endpoint (users — it also has a hidden column to guard).
 */
class IndexSortingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();

        $admin = User::factory()->recycle($tenant)->create(['name' => 'Mona']);
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);

        User::factory()->recycle($tenant)->create(['name' => 'Zeinab']);
        User::factory()->recycle($tenant)->create(['name' => 'Adel']);
    }

    public function test_defaults_to_id_descending(): void
    {
        $ids = $this->getJson('/api/v1/users')->assertOk()->json('data.*.id');

        $expected = $ids;
        rsort($expected);

        $this->assertSame($expected, $ids);
    }

    public function test_sorts_by_the_requested_column_and_direction(): void
    {
        $this->getJson('/api/v1/users?sort_by=name&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Adel', 'Mona', 'Zeinab']);

        $this->getJson('/api/v1/users?sort_by=name')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Zeinab', 'Mona', 'Adel']);
    }

    public function test_unknown_column_is_rejected(): void
    {
        $this->getJson('/api/v1/users?sort_by=name;drop table users')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort_by']);
    }

    public function test_hidden_column_is_rejected(): void
    {
        $this->getJson('/api/v1/users?sort_by=password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort_by']);
    }

    public function test_invalid_direction_is_rejected(): void
    {
        $this->getJson('/api/v1/users?sort_dir=up')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort_dir']);
    }
}
