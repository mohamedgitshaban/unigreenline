<?php

namespace Modules\Core\Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Modules\Core\Models\AuditLog;
use Modules\Core\Services\AuditLogService;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_entry_cannot_be_saved(): void
    {
        $entry = $this->app->make(AuditLogService::class)->record([
            'module' => 'auth',
            'entity_type' => 'User',
            'entity_id' => 'user-id',
            'operation' => 'LOGIN',
        ]);

        $this->expectException(LogicException::class);

        $entry->user_name = 'Changed';
        $entry->save();
    }

    public function test_an_entry_cannot_be_deleted(): void
    {
        $entry = $this->app->make(AuditLogService::class)->record([
            'module' => 'auth',
            'entity_type' => 'User',
            'entity_id' => 'user-id',
            'operation' => 'LOGIN',
        ]);

        $this->expectException(LogicException::class);

        $entry->delete();
    }

    public function test_a_new_entry_can_still_be_saved_directly(): void
    {
        $entry = new AuditLog([
            'occurred_at' => now(),
            'module' => 'auth',
            'entity_type' => 'User',
            'entity_id' => 'user-id',
            'operation' => 'LOGIN',
            'prev_hash' => 'GENESIS',
            'entry_hash' => 'some-hash',
        ]);

        $entry->save();

        $this->assertDatabaseHas('audit_log', ['entry_hash' => 'some-hash']);
    }
}
