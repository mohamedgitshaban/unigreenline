<?php

namespace Modules\Core\Tests\Feature\Services;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\AuditLogService;
use Tests\TestCase;

class AuditLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_entry_chains_from_genesis(): void
    {
        $entry = $this->service()->record($this->attributes());

        $this->assertSame(AuditLogService::GENESIS, $entry->prev_hash);
        $this->assertNotSame(AuditLogService::GENESIS, $entry->entry_hash);
    }

    public function test_second_entry_chains_to_the_first_entrys_hash(): void
    {
        $first = $this->service()->record($this->attributes());
        $second = $this->service()->record($this->attributes());

        $this->assertSame($first->entry_hash, $second->prev_hash);
    }

    public function test_verify_chain_returns_null_when_the_chain_is_intact(): void
    {
        $this->service()->record($this->attributes());
        $this->service()->record($this->attributes());

        $this->assertNull($this->service()->verifyChain());
    }

    public function test_verify_chain_detects_tampering_of_a_hash_covered_field(): void
    {
        $entry = $this->service()->record($this->attributes());
        $this->service()->record($this->attributes());

        DB::table('audit_log')->where('id', $entry->id)->update(['operation' => 'DELETE']);

        $this->assertSame($entry->id, $this->service()->verifyChain());
    }

    /**
     * The hash payload only covers {id, module, entity_type, entity_id,
     * operation, new_values, occurred_at} per spec §5.7.2 — user_name is
     * deliberately excluded, so tampering with it alone is not detectable
     * by this mechanism. This documents that scope boundary.
     */
    public function test_verify_chain_does_not_detect_tampering_outside_the_hash_payload(): void
    {
        $entry = $this->service()->record($this->attributes());

        DB::table('audit_log')->where('id', $entry->id)->update(['user_name' => 'Tampered Name']);

        $this->assertNull($this->service()->verifyChain());
    }

    private function service(): AuditLogService
    {
        return $this->app->make(AuditLogService::class);
    }

    private function attributes(): array
    {
        return [
            'module' => 'auth',
            'entity_type' => 'User',
            'entity_id' => 'user-id',
            'operation' => 'LOGIN',
            'user_name' => 'Original Name',
        ];
    }
}
