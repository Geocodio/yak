<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repository_config_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->longText('content')->nullable();
            $table->json('data')->nullable();
            $table->string('valid_commit_sha', 40)->nullable();
            $table->text('error')->nullable();
            $table->string('error_commit_sha', 40)->nullable();
            $table->json('error_pull_request')->nullable();
            $table->timestamps();
            $table->unique(['repository_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_config_files');
    }
};
