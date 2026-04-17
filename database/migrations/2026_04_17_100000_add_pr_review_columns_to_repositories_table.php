<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->boolean('pr_review_enabled')->default(false)->after('is_active');
            $table->json('pr_review_path_excludes')->nullable()->after('pr_review_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn(['pr_review_enabled', 'pr_review_path_excludes']);
        });
    }
};
