<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('type', 32);
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['thread_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_events');
    }
};
