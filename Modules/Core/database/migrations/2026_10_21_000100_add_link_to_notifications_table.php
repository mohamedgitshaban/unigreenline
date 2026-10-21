<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('link_entity')->nullable()->after('link_module');
            $table->string('link_url')->nullable()->after('link_id');
            $table->foreignUlid('actor_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('actor_id');
            $table->dropColumn(['link_entity', 'link_url']);
        });
    }
};
