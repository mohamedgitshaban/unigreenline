<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            // Kept (as "deleted user") when the sender's account goes, so the other side keeps its history.
            $table->foreignUlid('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('type', ['text', 'files', 'voice']);
            $table->text('body')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Ids are ULIDs, so this is also the conversation in time order.
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
