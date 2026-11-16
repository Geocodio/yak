<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->string('send_key', 36)->nullable();
            $table->unique(['created_by_user_id', 'send_key']);
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            // MariaDB drops the foreign key's own index when the unique index is added, so restore one first,
            // unless an earlier rollback already left one behind.
            if (! Schema::hasIndex('threads', ['created_by_user_id'])) {
                $table->index('created_by_user_id');
            }
            $table->dropUnique(['created_by_user_id', 'send_key']);
            $table->dropColumn('send_key');
        });
    }
};
