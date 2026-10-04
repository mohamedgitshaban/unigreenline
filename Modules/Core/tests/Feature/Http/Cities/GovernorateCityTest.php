<?php

namespace Modules\Core\Tests\Feature\Http\Cities;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\User;
use Tests\TestCase;

class GovernorateCityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/v1/governorates')->assertUnauthorized();
        $this->getJson('/api/v1/governorates/cairo/cities')->assertUnauthorized();
    }

    public function test_lists_all_governorates_with_slugs(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/governorates');

        $response->assertOk()
            ->assertJsonCount(27, 'data')
            ->assertJsonFragment(['slug' => 'kafr-el-sheikh', 'name' => 'Kafr El Sheikh']);
    }

    public function test_lists_cities_of_the_given_governorate_only(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/governorates/south-sinai/cities');

        $response->assertOk();
        $this->assertContains('Sharm El Sheikh', $response->json('data'));
        $this->assertNotContains('Hurghada', $response->json('data'));
    }

    public function test_unknown_governorate_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/governorates/atlantis/cities')->assertNotFound();
    }
}
