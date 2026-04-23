<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_deployments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('branch_name');
            $table->string('hostname')->unique();
            $table->string('container_name')->unique();
            $table->unsignedInteger('template_version');
            $table->string('status')->default('pending');
            $table->string('current_commit_sha')->nullable();
            $table->boolean('dirty')->default(false);
            $table->timestampTz('last_accessed_at')->nullable();
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('pr_state')->nullable();
            $table->foreignId('yak_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('public_share_token_hash')->nullable();
            $table->timestampTz('public_share_expires_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['repository_id', 'branch_name']);
            $table->index('status');
            $table->index('last_accessed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_deployments');
    }
};
