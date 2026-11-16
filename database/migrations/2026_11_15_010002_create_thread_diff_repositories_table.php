<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_diff_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_diff_id')->constrained()->cascadeOnDelete();
            $table->string('repo');
            $table->string('role');
            $table->string('base_sha', 64);
            $table->string('head_sha', 64);
            $table->unsignedInteger('files_count')->default(0);
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->boolean('is_throwaway')->default(false);
            $table->timestamps();
        });

        Schema::table('thread_diff_files', function (Blueprint $table) {
            $table->foreignId('thread_diff_repository_id')->nullable()->constrained('thread_diff_repositories')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('thread_diff_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('thread_diff_repository_id');
        });

        Schema::dropIfExists('thread_diff_repositories');
    }
};
