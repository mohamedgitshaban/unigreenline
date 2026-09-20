<?php

namespace Modules\Core\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\AuditLog;
use Modules\Core\Models\User;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_current_password_updates_the_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_incorrect_current_password_returns_422(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['current_password']);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_confirmation_mismatch_returns_422(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertUnauthorized();
    }

    public function test_changing_password_records_an_audit_entry_without_storing_the_password(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'module' => 'auth',
            'operation' => 'UPDATE',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);

        $entry = AuditLog::query()->where('entity_id', $user->id)->firstOrFail();
        $this->assertStringNotContainsString('new-secure-password', json_encode($entry->new_values));
    }
}
