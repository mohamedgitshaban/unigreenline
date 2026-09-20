<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Column names normalized to from_warehouse_id/to_warehouse_id (spec
     * §4.12 says "from_warehouse"/"to_warehouse") to stay consistent with
     * every other FK column in this schema, which is "_id"-suffixed.
     */
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUlid('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('batch_no');
            $table->unsignedInteger('qty_cartons');
            $table->date('transfer_date');
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('completed');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'product_id']);
        });

        DB::statement('ALTER TABLE transfers ADD CONSTRAINT chk_transfers_different_warehouses CHECK (from_warehouse_id <> to_warehouse_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
