<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_clones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_version_id')->constrained()->cascadeOnDelete();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->string('volume_name');
            $table->string('container_name');
            $table->timestamp('destroyed_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_clones');
    }
};
