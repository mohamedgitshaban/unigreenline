<?php

namespace Modules\Inventory\Tests\Feature\Http\Products;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\Tenant;
use Modules\Core\Models\User;
use Modules\Inventory\Models\InventoryBatch;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Warehouse;
use Tests\TestCase;

class ProductBatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_batches_are_paginated_and_only_belong_to_the_product(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Employee');
        Sanctum::actingAs($user);

        $product = Product::factory()->recycle($tenant)->create();
        $warehouse = Warehouse::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->count(12)->recycle([$tenant, $product, $warehouse])->create();
        InventoryBatch::factory()->recycle([$tenant, $warehouse])->create();

        $firstPage = $this->getJson("/api/v1/products/{$product->id}/batches?per_page=5");

        $firstPage->assertOk();
        $firstPage->assertJsonCount(5, 'data');
        $firstPage->assertJsonPath('meta.total', 12);
        $firstPage->assertJsonPath('meta.last_page', 3);
        $firstPage->assertJsonPath('data.0.warehouse.id', $warehouse->id);
        $this->assertNotNull($firstPage->json('links.next'));

        $lastPage = $this->getJson("/api/v1/products/{$product->id}/batches?per_page=5&page=3");

        $lastPage->assertOk();
        $lastPage->assertJsonCount(2, 'data');
        $this->assertNull($lastPage->json('links.next'));
    }

    public function test_batches_can_be_filtered_by_warehouse(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $tenant = Tenant::factory()->create();
        $user = User::factory()->recycle($tenant)->create();
        $user->assignRole('Warehouse Employee');
        Sanctum::actingAs($user);

        $product = Product::factory()->recycle($tenant)->create();
        $main = Warehouse::factory()->recycle($tenant)->create();
        $branch = Warehouse::factory()->recycle($tenant)->create();
        InventoryBatch::factory()->count(3)->recycle([$tenant, $product, $main])->create();
        InventoryBatch::factory()->count(2)->recycle([$tenant, $product, $branch])->create();

        $response = $this->getJson("/api/v1/products/{$product->id}/batches?filter[warehouse_id]={$branch->id}");

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_batches_of_a_product_from_another_tenant_are_not_found(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        Sanctum::actingAs($user);

        $product = Product::factory()->create();

        $response = $this->getJson("/api/v1/products/{$product->id}/batches");

        $response->assertNotFound();
    }
}
