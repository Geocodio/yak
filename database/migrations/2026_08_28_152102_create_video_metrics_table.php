<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('video_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('yak_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('artifact_id')->nullable()->constrained('artifacts')->nullOnDelete();
            $table->string('status', 16); // rendered | failed
            $table->unsignedInteger('render_ms');
            $table->unsignedBigInteger('output_bytes')->nullable();
            $table->decimal('duration_seconds', 8, 2)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_metrics');
    }
};
