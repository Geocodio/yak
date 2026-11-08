<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thread_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->string('session_id');
            $table->string('parent_session_id')->nullable();
            $table->string('kind')->default('main');
            $table->timestamps();

            $table->unique(['thread_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_sessions');
    }
};
