<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            // A batch row is deleted once it is emptied, so the id is kept for reference only (no foreign key).
            $table->ulid('batch_id')->nullable();
            $table->string('batch_no');
            $table->integer('qty_cartons');
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->boolean('is_free_gift')->default(false);
            $table->string('type', 40);
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_id', 40)->nullable();
            $table->foreignUlid('counterpart_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['tenant_id', 'warehouse_id', 'occurred_at']);
            $table->index(['tenant_id', 'product_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('batch_id');
        });

        // Stock that already exists becomes the opening balance, so the movements of every batch add up to what it holds.
        DB::table('inventory_batches')->where('qty_cartons', '>', 0)->orderBy('id')->chunk(500, function ($batches) {
            DB::table('stock_movements')->insert($batches->map(fn ($batch) => [
                'id' => (string) Str::ulid(),
                'tenant_id' => $batch->tenant_id,
                'warehouse_id' => $batch->warehouse_id,
                'product_id' => $batch->product_id,
                'batch_id' => $batch->id,
                'batch_no' => $batch->batch_no,
                'qty_cartons' => $batch->qty_cartons,
                'unit_cost' => $batch->cost_per_carton,
                'is_free_gift' => $batch->is_free_gift,
                'type' => 'opening_balance',
                'occurred_at' => $batch->rcv_date ?? now(),
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
