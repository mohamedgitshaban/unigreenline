<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boms', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->after('tenant_id');
            $table->unsignedInteger('version')->default(1)->after('name');
            // A draft BoM cannot be used by new orders; approved ones can.
            $table->enum('status', ['draft', 'approved'])->default('approved')->after('version');
            // Lower number wins when several approved BoMs make the same product.
            $table->unsignedSmallInteger('priority')->default(10)->after('status');
            $table->date('effective_from')->nullable()->after('priority');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->foreignUlid('routing_id')->nullable()->after('effective_to')->constrained('routings')->nullOnDelete();
            $table->text('instructions')->nullable()->after('notes');
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('bom_lines', function (Blueprint $table) {
            // Extra material the process is expected to waste, as a % of the quantity.
            $table->decimal('scrap_pct', 5, 2)->default(0)->after('qty_cartons');
            $table->boolean('optional')->default(false)->after('scrap_pct');
            // The unit the quantity was entered in (converted to cartons for stock).
            $table->foreignUlid('unit_id')->nullable()->after('optional')->constrained('units')->nullOnDelete();
            $table->unsignedInteger('unit_qty')->nullable()->after('unit_id');
            // The routing operation that uses the component.
            $table->foreignUlid('operation_id')->nullable()->after('unit_qty')->constrained('routing_operations')->nullOnDelete();
        });

        Schema::create('bom_by_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('bom_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            // Cartons produced per run.
            $table->unsignedInteger('qty_cartons');
            // Share of the run's cost the by-product carries (fixed_pct method).
            $table->decimal('cost_share_pct', 5, 2)->default(0);
            // Where it is received; null = the order's warehouse.
            $table->foreignUlid('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bom_id', 'product_id']);
        });

        // Immutable snapshots of a BoM as it was, one per revision.
        Schema::create('bom_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('bom_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision_no');
            $table->json('snapshot');
            $table->string('change_note')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['bom_id', 'revision_no']);
        });

        // Existing recipes get a code, in the order they were created.
        $counters = [];
        foreach (DB::table('boms')->orderBy('created_at')->orderBy('id')->get(['id', 'tenant_id']) as $bom) {
            $counters[$bom->tenant_id] = ($counters[$bom->tenant_id] ?? 0) + 1;
            DB::table('boms')->where('id', $bom->id)->update(['code' => 'BOM'.str_pad((string) $counters[$bom->tenant_id], 5, '0', STR_PAD_LEFT)]);
        }

        foreach ($counters as $tenantId => $last) {
            DB::table('manufacturing_sequences')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(), 'tenant_id' => $tenantId, 'key' => 'bom',
                'next_value' => $last + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_revisions');
        Schema::dropIfExists('bom_by_products');

        Schema::table('bom_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('operation_id');
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn(['scrap_pct', 'optional', 'unit_qty']);
        });

        Schema::table('boms', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'code']);
            $table->dropConstrainedForeignId('routing_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['code', 'version', 'status', 'priority', 'effective_from', 'effective_to', 'instructions', 'approved_at', 'archived_at']);
        });
    }
};
