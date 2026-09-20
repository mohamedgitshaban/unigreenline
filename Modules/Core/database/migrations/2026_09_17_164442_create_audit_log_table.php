<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The hash chain's integrity depends on strict insertion-order sequencing.
     * A ULID is not guaranteed monotonic across concurrent writes within the
     * same millisecond, so this table uses an auto-increment PK instead of
     * the ULID convention used elsewhere in the schema.
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->useCurrent();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->foreignUlid('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('warehouse_id')->nullable();
            $table->string('module');
            $table->string('entity_type');
            $table->string('entity_id')->nullable();
            $table->enum('operation', [
                'INSERT', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'EXPORT', 'IMPORT', 'APPROVE',
            ]);
            $table->json('prev_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('request_id')->nullable();
            $table->string('prev_hash');
            $table->string('entry_hash')->unique();

            $table->index(['entity_type', 'entity_id']);
            $table->index('occurred_at');
            $table->index('module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
