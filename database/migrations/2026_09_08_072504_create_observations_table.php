<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->string('repo')->nullable();
            $table->string('source');
            $table->string('kind');
            $table->string('outcome');
            $table->text('summary');
            $table->string('subject')->nullable();
            $table->string('reference_url')->nullable();

            // nullOnDelete, unlike task_logs' cascade: deleting a task must
            // not erase the record that Yak looked at something and acted.
            $table->foreignId('yak_task_id')->nullable()->constrained('tasks')->nullOnDelete();

            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['repo', 'created_at']);
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observations');
    }
};
