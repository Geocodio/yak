<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('pr_merged_at')->nullable()->after('pr_url');
            $table->timestamp('pr_closed_at')->nullable()->after('pr_merged_at');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['pr_merged_at', 'pr_closed_at']);
        });
    }
};
