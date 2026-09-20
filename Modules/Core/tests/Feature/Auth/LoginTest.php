<?php

namespace Modules\Core\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Database\Seeders\RolePermissionSeeder;
use Modules\Core\Models\User;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_returns_token_and_user_profile(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create(['email' => 'jane@example.com']);
        $user->assignRole('Sales Rep');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'password',
        ]);

        $response->assertOk();
        $this->assertIsString($response->json('token'));
        $response->assertJsonPath('user.id', $user->id);
        $response->assertJsonPath('user.email', 'jane@example.com');
        $response->assertJsonPath('user.role', 'Sales Rep');
        $response->assertJsonPath('user.permissions', [
            'sales.view', 'sales.add', 'sales.edit', 'sales.print',
            'crm.view', 'crm.add', 'crm.edit', 'crm.print',
        ]);
    }

    public function test_unknown_email_returns_422(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);
    }

    public function test_wrong_password_returns_422(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'not-the-password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);
    }

    public function test_wrong_password_increments_failed_logins_counter(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com', 'failed_logins' => 0]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'not-the-password',
        ]);

        $this->assertSame(1, $user->fresh()->failed_logins);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->create(['email' => 'jane@example.com', 'status' => 'suspended']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email' => 'This account is not active.']);
    }

    public function test_missing_credentials_returns_422_for_both_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_successful_login_records_a_login_audit_entry(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'module' => 'auth',
            'operation' => 'LOGIN',
            'entity_type' => 'User',
            'entity_id' => $user->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_sixth_login_attempt_within_a_minute_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'jane@example.com',
                'password' => 'not-the-password',
            ]);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'not-the-password',
        ]);

        $response->assertTooManyRequests();
    }
}
