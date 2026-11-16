<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array COLUMNS = [
        'threads' => ['effort', 'runner_effort'],
        'claude_accounts' => ['default_effort'],
        'user_repository_settings' => ['effort'],
        'pending_steering_messages' => ['effort'],
        'task_runs' => ['effort'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->string($column, 16)->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($columns));
        }
    }
};
