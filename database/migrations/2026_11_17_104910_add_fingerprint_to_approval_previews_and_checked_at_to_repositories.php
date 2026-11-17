<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_previews', function (Blueprint $table) {
            $table->string('fingerprint', 64)->nullable()->after('concerns_version');
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->timestamp('approval_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('approval_previews', function (Blueprint $table) {
            $table->dropColumn('fingerprint');
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn('approval_checked_at');
        });
    }
};
