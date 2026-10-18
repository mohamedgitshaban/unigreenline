<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('message_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['file', 'image', 'voice']);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            // Voice messages only: how long the recording runs, as reported by the recorder.
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_attachments');
    }
};
