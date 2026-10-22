<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('tagged_co_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('early_warning')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tagged_co_owner_user_id');
            $table->dropColumn('early_warning');
        });
    }
};
