<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->uuid('spawn_key')->nullable()->after('parent_thread_id');
            $table->timestamp('split_requested_at')->nullable();
            $table->unique(['parent_thread_id', 'spawn_key'], 'threads_parent_thread_id_spawn_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropUnique('threads_parent_thread_id_spawn_key_unique');
            $table->dropColumn(['spawn_key', 'split_requested_at']);
        });
    }
};
