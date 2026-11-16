<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained()->cascadeOnDelete();
            $table->string('version', 64);
            $table->string('status', 16)->default('fetching');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['dataset_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_versions');
    }
};
