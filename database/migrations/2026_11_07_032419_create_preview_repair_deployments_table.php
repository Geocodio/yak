<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preview_repair_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preview_repair_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_deployment_id')->constrained()->cascadeOnDelete();
            $table->string('head_sha', 40)->nullable();
            $table->timestamp('failed_at');
            $table->timestamp('notified_at')->nullable();

            $table->unique(['preview_repair_id', 'branch_deployment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_repair_deployments');
    }
};
