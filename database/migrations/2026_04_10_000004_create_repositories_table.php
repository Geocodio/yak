<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('setup_status')->default('pending');
            $table->foreignId('setup_task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('path');
            $table->string('default_branch')->default('main');
            $table->string('ci_system');
            $table->string('sentry_project')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
