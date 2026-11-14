<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_pairing_codes', function (Blueprint $table) {
            $table->char('request_id_hash', 64)->nullable();
            $table->text('encrypted_token')->nullable();
        });

        Schema::table('device_authorizations', function (Blueprint $table) {
            $table->timestamp('redeemed_at')->nullable();
            $table->char('request_id_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_pairing_codes', function (Blueprint $table) {
            $table->dropColumn(['request_id_hash', 'encrypted_token']);
        });

        Schema::table('device_authorizations', function (Blueprint $table) {
            $table->dropColumn(['redeemed_at', 'request_id_hash']);
        });
    }
};
