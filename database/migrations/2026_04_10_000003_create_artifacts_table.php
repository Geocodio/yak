<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('yak_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('type');
            $table->string('filename');
            $table->string('disk_path');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
