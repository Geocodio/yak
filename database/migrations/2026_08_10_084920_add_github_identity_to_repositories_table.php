<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Split GitHub's mutable coordinates out of `slug`.
     *
     * `slug` stays the stable internal identity (route key, `tasks.repo`,
     * disk path, incus template alias, preview hostname). `github_repo_id`
     * and `github_full_name` track where the repository currently lives on
     * GitHub, so a rename or transfer no longer orphans inbound webhooks.
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->unsignedBigInteger('github_repo_id')->nullable()->unique()->after('slug');
            $table->string('github_full_name')->nullable()->index()->after('github_repo_id');
        });

        DB::table('repositories')->update(['github_full_name' => DB::raw('slug')]);
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropUnique(['github_repo_id']);
            $table->dropIndex(['github_full_name']);
            $table->dropColumn(['github_repo_id', 'github_full_name']);
        });
    }
};
