<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->string('session_id');
            $table->string('rel_path', 500);
            $table->string('mode');
            $table->unsignedBigInteger('length')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->string('checkpoint_sha256', 64)->nullable();
            $table->timestamps();

            $table->unique(['thread_id', 'session_id', 'rel_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_files');
    }
};
