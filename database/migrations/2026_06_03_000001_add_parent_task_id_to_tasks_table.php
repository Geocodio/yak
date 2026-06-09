<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('parent_task_id')
                ->nullable()
                ->after('id')
                ->constrained('tasks')
                ->nullOnDelete();
            $table->unsignedInteger('pr_number')->nullable()->after('pr_url');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_task_id');
            $table->dropColumn('pr_number');
        });
    }
};
