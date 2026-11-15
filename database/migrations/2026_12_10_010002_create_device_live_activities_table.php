<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_live_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->timestamp('started_at');
            $table->timestamps();

            $table->unique(['device_id', 'thread_id']);
            $table->index(['device_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_live_activities');
    }
};
