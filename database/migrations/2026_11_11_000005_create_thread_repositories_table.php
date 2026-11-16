<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->string('repo');
            $table->string('role', 16)->default('primary');
            $table->string('sandbox_path');
            $table->string('setup_status', 16)->default('pending');
            $table->text('setup_error')->nullable();
            $table->unsignedSmallInteger('merge_position')->nullable();
            $table->string('branch')->nullable();
            $table->string('pull_request_url')->nullable();
            $table->unsignedSmallInteger('ci_retry_count')->default(0);
            $table->timestamps();

            $table->unique(['thread_id', 'repo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_repositories');
    }
};
