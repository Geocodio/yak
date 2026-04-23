<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_deployment_id')->constrained()->cascadeOnDelete();
            $table->string('level')->default('info');
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['branch_deployment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_logs');
    }
};
