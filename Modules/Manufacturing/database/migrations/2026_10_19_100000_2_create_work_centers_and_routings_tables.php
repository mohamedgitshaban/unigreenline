<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_centers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            // How many operations it can run at the same time.
            $table->unsignedSmallInteger('capacity')->default(1);
            // Output speed against the nominal run time: 80 = takes 25% longer.
            $table->decimal('efficiency_pct', 5, 2)->default(100);
            // Machine cost and operator cost, per hour of operation.
            $table->decimal('cost_per_hour', 10, 2)->default(0);
            $table->decimal('labor_cost_per_hour', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Weekly availability: one row per shift. 0 = Sunday ... 6 = Saturday.
        Schema::create('work_center_calendars', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('work_center_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->index(['work_center_id', 'weekday']);
        });

        Schema::create('work_center_downtimes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('work_center_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['work_center_id', 'starts_at', 'ends_at']);
        });

        Schema::create('routings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('routing_operations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('routing_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('name');
            $table->foreignUlid('work_center_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('setup_minutes')->default(0);
            // Minutes per carton of finished product.
            $table->decimal('run_minutes', 10, 2)->default(0);
            $table->unsignedInteger('cleanup_minutes')->default(0);
            // The operation that must finish first; null = the previous one in sequence.
            $table->foreignUlid('depends_on_id')->nullable()->constrained('routing_operations')->nullOnDelete();
            $table->text('instructions')->nullable();
            $table->timestamps();

            $table->unique(['routing_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_operations');
        Schema::dropIfExists('routings');
        Schema::dropIfExists('work_center_downtimes');
        Schema::dropIfExists('work_center_calendars');
        Schema::dropIfExists('work_centers');
    }
};
