<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const STATUSES = "'draft','confirmed','planned','in_progress','paused','completed','cancelled'";

    private const LEGACY_STATUSES = "'draft','completed','cancelled'";

    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE production_orders MODIFY status ENUM('.self::STATUSES.") NOT NULL DEFAULT 'draft'");
            // Orders raised by planning or a sales order get their expiry when stock is received.
            DB::statement('ALTER TABLE production_orders MODIFY exp_date DATE NULL');
        }

        Schema::table('production_orders', function (Blueprint $table) {
            $table->string('number', 40)->nullable()->after('tenant_id');
            $table->unsignedTinyInteger('priority')->default(1)->after('status'); // 0 low, 1 normal, 2 high, 3 urgent
            $table->enum('reservation_status', ['none', 'partial', 'reserved'])->default('none')->after('priority');
            $table->dateTime('planned_start')->nullable()->after('production_date');
            $table->dateTime('planned_finish')->nullable()->after('planned_start');
            $table->dateTime('actual_start')->nullable()->after('planned_finish');
            $table->dateTime('actual_finish')->nullable()->after('actual_start');
            $table->foreignUlid('assigned_to')->nullable()->after('created_by')->constrained('users')->nullOnDelete();

            $table->unsignedInteger('produced_qty_cartons')->default(0)->after('output_qty_cartons');
            $table->unsignedInteger('rejected_qty_cartons')->default(0)->after('produced_qty_cartons');

            $table->foreignUlid('bom_revision_id')->nullable()->after('bom_id')->constrained('bom_revisions')->nullOnDelete();
            $table->foreignUlid('parent_order_id')->nullable()->after('bom_revision_id')->constrained('production_orders')->nullOnDelete();
            $table->string('source_type', 40)->nullable();
            $table->string('source_id', 40)->nullable();
            $table->text('cancellation_reason')->nullable();

            // Estimates are fixed when the order is confirmed; actuals build up as work is recorded.
            $table->decimal('planned_material_cost', 14, 2)->default(0);
            $table->decimal('planned_operation_cost', 14, 2)->default(0);
            $table->decimal('planned_overhead_cost', 14, 2)->default(0);
            $table->decimal('actual_operation_cost', 14, 2)->default(0);
            $table->decimal('actual_overhead_cost', 14, 2)->default(0);
            $table->decimal('scrap_cost', 14, 2)->default(0);
            // Value moved into finished goods / by-products so far, and the variance written off at completion.
            $table->decimal('capitalized_cost', 14, 2)->default(0);
            // The part of capitalized_cost that was material (the rest is labour, machine and overhead).
            $table->decimal('capitalized_material', 14, 2)->default(0);
            $table->decimal('variance_cost', 14, 2)->default(0);
            // Whether this order books materials through Work in Progress; fixed when it is confirmed.
            $table->boolean('wip_accounting')->default(false);
            $table->foreignUlid('variance_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->unique(['tenant_id', 'number']);
            $table->index(['source_type', 'source_id']);
            $table->index('planned_start');
        });

        // Existing orders get a number, in the order they were created.
        $counters = [];
        foreach (DB::table('production_orders')->orderBy('created_at')->orderBy('id')->get(['id', 'tenant_id']) as $order) {
            $counters[$order->tenant_id] = ($counters[$order->tenant_id] ?? 0) + 1;
            DB::table('production_orders')->where('id', $order->id)->update(['number' => 'MO'.str_pad((string) $counters[$order->tenant_id], 5, '0', STR_PAD_LEFT)]);
        }

        foreach ($counters as $tenantId => $last) {
            DB::table('manufacturing_sequences')->insert([
                'id' => (string) Str::ulid(), 'tenant_id' => $tenantId, 'key' => 'mo',
                'next_value' => $last + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Completed legacy orders produced exactly what they planned.
        DB::table('production_orders')->where('status', 'completed')->update(['produced_qty_cartons' => DB::raw('output_qty_cartons'), 'capitalized_cost' => DB::raw('material_cost + conversion_cost'), 'capitalized_material' => DB::raw('material_cost')]);

        // The components an order needs, fixed when it is confirmed so a later
        // BoM change cannot move the goalposts of an order already in progress.
        Schema::create('production_order_components', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('bom_line_id')->nullable()->constrained('bom_lines')->nullOnDelete();
            // Exact need (with scrap allowance) in cartons, and the whole cartons stock will actually be issued in.
            $table->decimal('required_qty', 14, 4);
            $table->unsignedInteger('required_cartons');
            $table->unsignedInteger('reserved_cartons')->default(0);
            $table->unsignedInteger('consumed_cartons')->default(0);
            $table->decimal('scrap_pct', 5, 2)->default(0);
            $table->boolean('optional')->default(false);
            $table->foreignUlid('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->timestamps();

            $table->unique(['production_order_id', 'product_id'], 'poc_order_product_unique');
        });

        Schema::table('production_consumptions', function (Blueprint $table) {
            $table->foreignUlid('component_id')->nullable()->after('production_order_id')->constrained('production_order_components')->nullOnDelete();
            $table->foreignUlid('work_order_id')->nullable()->after('component_id')->constrained('work_orders')->nullOnDelete();
            // consume = material used by the product; scrap = material lost on the way.
            $table->enum('kind', ['consume', 'scrap'])->default('consume')->after('work_order_id');
            // Scrap whose cost is carried by the finished product instead of being expensed.
            $table->boolean('absorbed')->default(false)->after('kind');
            $table->foreignUlid('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('production_outputs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->enum('kind', ['finished', 'by_product'])->default('finished');
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->string('batch_no');
            $table->unsignedInteger('qty_cartons');
            // Units made in the same run that failed inspection and were not received.
            $table->unsignedInteger('rejected_cartons')->default(0);
            $table->decimal('cost_per_carton', 10, 2)->default(0);
            $table->decimal('line_value', 14, 2)->default(0);
            $table->foreignUlid('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['production_order_id', 'kind']);
        });

        Schema::create('production_scraps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignUlid('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('work_order_id')->nullable()->constrained('work_orders')->nullOnDelete();
            // component = material wasted (leaves stock); finished = output rejected (never received).
            $table->enum('kind', ['component', 'finished']);
            $table->foreignUlid('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty_cartons');
            $table->string('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('approved');
            $table->decimal('scrap_cost', 14, 2)->default(0);
            $table->foreignUlid('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['production_order_id', 'status']);
        });

        // Append-only trail of what happened to an order and who did it.
        Schema::create('production_order_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('production_order_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['production_order_id', 'created_at']);
        });

        // Replays of the same request (a client retry) find their original result here.
        Schema::create('manufacturing_operations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('production_order_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('idempotency_key', 100);
            $table->json('result')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['production_order_id', 'action', 'idempotency_key'], 'manufacturing_operations_replay_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturing_operations');
        Schema::dropIfExists('production_order_events');
        Schema::dropIfExists('production_scraps');
        Schema::dropIfExists('production_outputs');

        Schema::table('production_consumptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropConstrainedForeignId('work_order_id');
            $table->dropConstrainedForeignId('component_id');
            $table->dropColumn(['kind', 'absorbed']);
        });

        Schema::dropIfExists('production_order_components');

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'number']);
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropIndex(['planned_start']);
            $table->dropConstrainedForeignId('variance_journal_entry_id');
            $table->dropConstrainedForeignId('parent_order_id');
            $table->dropConstrainedForeignId('bom_revision_id');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn([
                'number', 'priority', 'reservation_status', 'planned_start', 'planned_finish', 'actual_start', 'actual_finish',
                'produced_qty_cartons', 'rejected_qty_cartons', 'source_type', 'source_id', 'cancellation_reason',
                'planned_material_cost', 'planned_operation_cost', 'planned_overhead_cost', 'actual_operation_cost',
                'actual_overhead_cost', 'scrap_cost', 'capitalized_cost', 'capitalized_material', 'variance_cost', 'wip_accounting',
            ]);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::table('production_orders')->whereIn('status', ['confirmed', 'planned', 'in_progress', 'paused'])->update(['status' => 'draft']);
            DB::table('production_orders')->whereNull('exp_date')->update(['exp_date' => DB::raw('production_date')]);
            DB::statement('ALTER TABLE production_orders MODIFY status ENUM('.self::LEGACY_STATUSES.") NOT NULL DEFAULT 'draft'");
            DB::statement('ALTER TABLE production_orders MODIFY exp_date DATE NOT NULL');
        }
    }
};
