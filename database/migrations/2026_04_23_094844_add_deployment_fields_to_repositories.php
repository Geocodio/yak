<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->boolean('deployments_enabled')->default(false)->after('slug');
            $table->json('preview_manifest')->nullable()->after('deployments_enabled');
            $table->json('preview_env_overrides')->nullable()->after('preview_manifest');
            $table->unsignedInteger('current_template_version')->default(0)->after('preview_env_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn([
                'deployments_enabled',
                'preview_manifest',
                'preview_env_overrides',
                'current_template_version',
            ]);
        });
    }
};
