<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('token', 200)->index();
            $table->string('environment', 16);
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_push_tokens');
    }
};
