<?php

namespace Modules\Core\Tests\Feature\Http\Cities;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\User;
use Tests\TestCase;

class EgyptCityIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/cities/egypt');

        $response->assertUnauthorized();
    }

    public function test_returns_cities_with_their_governorate(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/cities/egypt');

        $response->assertOk()
            ->assertJsonStructure(['data' => [['name', 'governorate']]])
            ->assertJsonFragment(['name' => 'Alexandria', 'governorate' => 'Alexandria'])
            ->assertJsonFragment(['name' => 'Sharm El Sheikh', 'governorate' => 'South Sinai']);
        $this->assertCount(27, collect($response->json('data'))->pluck('governorate')->unique());
    }
}
