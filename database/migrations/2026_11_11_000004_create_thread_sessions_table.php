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
            $table->string('session_id', 64);
            $table->string('parent_session_id', 64)->nullable();
            $table->string('kind', 16)->default('main');
            $table->timestamps();

            $table->unique(['thread_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_sessions');
    }
};
