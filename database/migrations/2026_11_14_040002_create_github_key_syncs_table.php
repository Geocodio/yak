<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_key_syncs', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_id');
            $table->string('github_login')->nullable();
            $table->string('status', 24);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('stale_since')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_key_syncs');
    }
};
