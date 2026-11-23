<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capacity_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('recorded_at')->index();
            $table->unsignedInteger('awake_count');
            $table->unsignedInteger('awake_cap');
            $table->unsignedBigInteger('memory_total_bytes');
            $table->unsignedBigInteger('memory_used_bytes');
            $table->float('cpu_used_ratio');
            $table->unsignedInteger('memory_floor_evictions')->default(0);
        });

        Schema::create('sandbox_evictions', function (Blueprint $table) {
            $table->id();
            $table->string('reason', 32);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sandbox_evictions');
        Schema::dropIfExists('capacity_samples');
    }
};
