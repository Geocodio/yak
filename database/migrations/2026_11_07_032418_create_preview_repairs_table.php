<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preview_repairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('phase');
            $table->text('error_excerpt');
            $table->foreignId('yak_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('verdict')->default('pending');
            $table->text('cause')->nullable();
            $table->timestamp('first_failed_at');
            $table->timestamp('last_failed_at');
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Not unique: whether a repair absorbs a new failure is a liveness
            // question answered by PreviewRepair::liveFor(), so a cause whose
            // repair was closed unmerged can be investigated again.
            $table->index(['repository_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_repairs');
    }
};
