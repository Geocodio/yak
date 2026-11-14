<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['devices', 'device_authorizations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('kind', 16)->default('computer');
                $table->string('model', 64)->nullable();
                $table->string('app_version', 32)->nullable();
                $table->string('os', 32)->nullable()->change();
                $table->string('arch', 16)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['devices', 'device_authorizations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['kind', 'model', 'app_version']);
            });
        }
    }
};
