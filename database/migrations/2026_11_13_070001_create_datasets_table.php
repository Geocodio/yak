<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('source_prefix', 500);
            $table->string('version_key', 500);
            $table->string('mount_path', 200);
            $table->unsignedInteger('declared_size_gb')->nullable();
            $table->unsignedInteger('size_cap_gb')->nullable();
            $table->string('volume_name');
            $table->string('status', 16)->default('absent');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datasets');
    }
};
