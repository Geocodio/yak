<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            if (! Schema::hasColumn('repositories', 'build_credentials')) {
                $table->text('build_credentials')->nullable();
            }
            if (! Schema::hasColumn('repositories', 'setup_script_hash')) {
                $table->string('setup_script_hash', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['build_credentials', 'setup_script_hash']);
        });
    }
};
