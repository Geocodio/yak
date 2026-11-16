<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thread_repositories', function (Blueprint $table) {
            $table->string('last_pushed_sha', 64)->nullable()->after('last_commit_sha');
        });
    }

    public function down(): void
    {
        Schema::table('thread_repositories', function (Blueprint $table) {
            $table->dropColumn('last_pushed_sha');
        });
    }
};
