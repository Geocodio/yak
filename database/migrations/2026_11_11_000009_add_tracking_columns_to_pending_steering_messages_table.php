<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_steering_messages', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique();
            $table->string('model', 64)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('absorbed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pending_steering_messages', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'model', 'delivered_at', 'absorbed_at']);
        });
    }
};
