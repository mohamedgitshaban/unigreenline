<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Documents attached to a recipe, order, operation, routing or quality check: drawings, instructions, certificates, photos.
        Schema::create('manufacturing_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('attachable_type', 40);
            $table->string('attachable_id', 40);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size');
            $table->string('description')->nullable();
            $table->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id'], 'manufacturing_attachments_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturing_attachments');
    }
};
