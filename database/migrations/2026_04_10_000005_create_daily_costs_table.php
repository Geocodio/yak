<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_costs', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->decimal('total_usd', 10, 4)->default(0);
            $table->unsignedInteger('task_count')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_costs');
    }
};
