<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->unsignedTinyInteger('approval_level')->default(1);
            $table->string('approval_mode')->default('shadow');
            $table->foreignId('approval_changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approval_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approval_changed_by_user_id');
            $table->dropColumn(['approval_level', 'approval_mode', 'approval_changed_at']);
        });
    }
};
