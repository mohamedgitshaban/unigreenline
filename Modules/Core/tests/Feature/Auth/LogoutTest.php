<?php

namespace Modules\Core\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Models\User;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_revokes_the_token_so_it_can_no_longer_authenticate(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'password',
        ])->json('token');

        $logoutResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout');
        $logoutResponse->assertOk();

        // Sanctum's RequestGuard caches the resolved user on the guard
        // singleton, which persists across simulated requests within one
        // test method (unlike real requests, which each boot a fresh
        // application). Forget it so this request re-resolves from the DB.
        Auth::forgetGuards();

        $reuseResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');
        $reuseResponse->assertUnauthorized();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertUnauthorized();
    }

    public function test_logout_records_a_logout_audit_entry(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'password',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/auth/logout');

        $this->assertDatabaseHas('audit_log', [
            'module' => 'auth',
            'operation' => 'LOGOUT',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);
    }
}
