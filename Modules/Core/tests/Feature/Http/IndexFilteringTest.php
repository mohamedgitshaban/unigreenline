<?php

namespace Modules\Core\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\ProductCategory;
use Tests\TestCase;

/**
 * Covers the shared Filterable trait through representative index endpoints:
 * users (has a hidden column to guard) and products (relation search).
 */
class IndexFilteringTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();

        $admin = User::factory()->recycle($this->tenant)->create(['name' => 'Mona', 'email' => 'mona@example.com']);
        $admin->assignRole('Administrator');
        Sanctum::actingAs($admin);
    }

    public function test_search_matches_any_searchable_column(): void
    {
        User::factory()->recycle($this->tenant)->create(['name' => 'Zeinab', 'email' => 'z@acme.test']);
        User::factory()->recycle($this->tenant)->create(['name' => 'Adel Acme', 'email' => 'adel@example.com']);
        User::factory()->recycle($this->tenant)->create(['name' => 'Omar', 'email' => 'omar@example.com']);

        $this->getJson('/api/v1/users?search=acme&sort_by=name&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Adel Acme', 'Zeinab']);
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        User::factory()->recycle($this->tenant)->create(['name' => '100% Vet']);

        $this->getJson('/api/v1/users?search=%25')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['100% Vet']);
    }

    public function test_search_matches_a_related_models_column(): void
    {
        $category = ProductCategory::factory()->recycle($this->tenant)->create(['name' => 'Antibiotics']);
        Product::factory()->recycle($this->tenant)->for($category, 'category')->create(['name' => 'Oxytetracycline']);
        Product::factory()->recycle($this->tenant)->create(['name' => 'Ivermectin']);

        $this->getJson('/api/v1/products?search=antibio')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Oxytetracycline']);
    }

    public function test_filters_by_exact_value_list_and_like_operators(): void
    {
        User::factory()->recycle($this->tenant)->create(['name' => 'Zeinab', 'status' => 'inactive']);
        User::factory()->recycle($this->tenant)->create(['name' => 'Adel', 'status' => 'active']);

        $this->getJson('/api/v1/users?filter[status]=inactive')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Zeinab']);

        $this->getJson('/api/v1/users?filter[name][in]=Adel,Zeinab&sort_by=name&sort_dir=asc')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Adel', 'Zeinab']);

        $this->getJson('/api/v1/users?filter[name][like]=ein')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Zeinab']);
    }

    public function test_date_only_range_on_a_timestamp_column_includes_the_boundary_days(): void
    {
        User::factory()->recycle($this->tenant)->create(['name' => 'Early', 'created_at' => '2026-01-01 08:00:00']);
        User::factory()->recycle($this->tenant)->create(['name' => 'Inside', 'created_at' => '2026-02-15 23:30:00']);
        User::factory()->recycle($this->tenant)->create(['name' => 'Late', 'created_at' => '2026-03-01 00:00:00']);

        $this->getJson('/api/v1/users?filter[created_at][gte]=2026-02-01&filter[created_at][lte]=2026-02-15')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Inside']);
    }

    public function test_null_operator_and_boolean_values_are_supported(): void
    {
        User::factory()->recycle($this->tenant)->create(['name' => 'Never', 'last_login' => null, 'mfa_enabled' => true]);
        User::factory()->recycle($this->tenant)->create(['name' => 'Seen', 'last_login' => now(), 'mfa_enabled' => false]);

        $this->getJson('/api/v1/users?filter[last_login][null]=true&filter[mfa_enabled]=true')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Never']);
    }

    public function test_empty_filter_value_is_ignored(): void
    {
        $this->getJson('/api/v1/users?filter[status]=')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Mona']);
    }

    public function test_pagination_links_keep_the_filters(): void
    {
        User::factory()->recycle($this->tenant)->count(2)->create(['status' => 'inactive']);

        $nextPage = $this->getJson('/api/v1/users?filter[status]=inactive&per_page=1')
            ->assertOk()
            ->json('links.next');

        $this->assertStringContainsString('filter%5Bstatus%5D=inactive', $nextPage);
    }

    public function test_unknown_column_is_rejected_with_422(): void
    {
        $this->getJson('/api/v1/users?filter[name;drop table users]=x')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter']);
    }

    public function test_hidden_column_is_rejected_with_422(): void
    {
        $this->getJson('/api/v1/users?filter[password]=secret')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter']);
    }

    public function test_unknown_operator_is_rejected_with_422(): void
    {
        $this->getJson('/api/v1/users?filter[name][regexp]=.*')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter.name' => 'Unknown filter operator [regexp]']);
    }
}
