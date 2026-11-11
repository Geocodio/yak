<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_authorizations', function (Blueprint $table) {
            $table->id();
            $table->char('device_code_hash', 64)->unique();
            $table->string('user_code', 9)->unique();
            $table->string('name', 100);
            $table->string('os', 16);
            $table->string('arch', 16);
            $table->string('cli_version', 32)->nullable();
            $table->string('requested_from_ip', 45)->nullable();
            $table->string('status', 16)->default('pending');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('encrypted_token')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->unsignedSmallInteger('poll_interval')->default(5);
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_authorizations');
    }
};
