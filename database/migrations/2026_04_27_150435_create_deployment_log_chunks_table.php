<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Streaming output for a deployment_logs row, captured a chunk
        // at a time as a long-running command produces stdout/stderr.
        // INSERT-only — never updated — so docker build / composer
        // install / npm ci output isn't bounded by row-rewrite cost.
        Schema::create('deployment_log_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deployment_log_id')->constrained()->cascadeOnDelete();
            $table->longText('chunk');
            $table->timestamp('created_at');

            $table->index(['deployment_log_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_log_chunks');
    }
};
