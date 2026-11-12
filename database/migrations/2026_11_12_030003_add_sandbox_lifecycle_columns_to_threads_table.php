<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'last_refresh_commit' => 40,
        'wip_commit_sha' => 40,
        'local_repo_path' => 255,
        'git_author_name' => 255,
        'git_author_email' => 255,
    ];

    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            foreach (self::COLUMNS as $column => $length) {
                if (! Schema::hasColumn('threads', $column)) {
                    $table->string($column, $length)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::COLUMNS));
        });
    }
};
