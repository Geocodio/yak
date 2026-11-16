<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_file_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('offset');
            $table->unsignedBigInteger('length');
            $table->string('sha256', 64);
            $table->string('path', 1000);
            $table->timestamps();

            $table->index(['session_file_id', 'offset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_file_segments');
    }
};
