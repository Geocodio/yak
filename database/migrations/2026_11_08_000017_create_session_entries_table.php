<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_entries', function (Blueprint $table) {
            $table->id();
            $table->string('session_id');
            $table->string('uuid');
            $table->string('parent_uuid')->nullable();
            $table->foreignId('session_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('offset');
            $table->timestamps();

            $table->unique(['session_id', 'uuid']);
            $table->index('parent_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_entries');
    }
};
