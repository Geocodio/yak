<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->string('preview_hostname', 120)->nullable()->unique();
            $table->timestamp('preview_last_request_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropUnique(['preview_hostname']);
            $table->dropColumn(['preview_hostname', 'preview_last_request_at']);
        });
    }
};
