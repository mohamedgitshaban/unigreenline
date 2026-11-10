<?php

namespace Modules\Inventory\Tests\Feature\Http\Categories;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\ProductCategory;
use Tests\TestCase;

/**
 * A product class can have sub-classes: two levels, no more.
 */
class ProductCategoryTreeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($this->tenant)->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);
    }

    public function test_a_sub_class_is_created_under_a_class_and_shows_its_parent(): void
    {
        $parent = ProductCategory::factory()->recycle($this->tenant)->create(['name' => 'Antibiotics']);

        $this->postJson('/api/v1/categories', ['name' => 'Injectable antibiotics', 'parent_id' => $parent->id])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $parent->id)
            ->assertJsonPath('data.parent.name', 'Antibiotics');

        $this->getJson("/api/v1/categories/{$parent->id}")->assertOk()->assertJsonPath('data.children_count', 1);
    }

    public function test_sub_classes_of_a_class_are_listed_with_a_filter(): void
    {
        $parent = ProductCategory::factory()->recycle($this->tenant)->create();
        $child = ProductCategory::factory()->recycle($this->tenant)->create(['parent_id' => $parent->id]);
        ProductCategory::factory()->recycle($this->tenant)->create();

        $this->getJson("/api/v1/categories?filter[parent_id]={$parent->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $child->id);
    }

    public function test_a_sub_class_cannot_have_a_sub_class_of_its_own(): void
    {
        $parent = ProductCategory::factory()->recycle($this->tenant)->create();
        $child = ProductCategory::factory()->recycle($this->tenant)->create(['parent_id' => $parent->id]);

        $this->postJson('/api/v1/categories', ['name' => 'Too deep', 'parent_id' => $child->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_a_class_with_sub_classes_cannot_become_a_sub_class(): void
    {
        $parent = ProductCategory::factory()->recycle($this->tenant)->create();
        ProductCategory::factory()->recycle($this->tenant)->create(['parent_id' => $parent->id]);
        $other = ProductCategory::factory()->recycle($this->tenant)->create();

        $this->putJson("/api/v1/categories/{$parent->id}", ['parent_id' => $other->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id' => 'This class has sub-classes of its own, so it cannot become a sub-class.']);
    }

    public function test_a_category_cannot_sit_under_itself(): void
    {
        $category = ProductCategory::factory()->recycle($this->tenant)->create();

        $this->putJson("/api/v1/categories/{$category->id}", ['parent_id' => $category->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_a_parent_from_another_company_is_refused(): void
    {
        $foreign = ProductCategory::factory()->create();

        $this->postJson('/api/v1/categories', ['name' => 'Sneaky', 'parent_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_a_class_that_still_has_sub_classes_cannot_be_deleted(): void
    {
        $parent = ProductCategory::factory()->recycle($this->tenant)->create();
        ProductCategory::factory()->recycle($this->tenant)->create(['parent_id' => $parent->id]);

        $this->deleteJson("/api/v1/categories/{$parent->id}")->assertConflict();

        $this->assertModelExists($parent);
    }
}
