<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_up_pending_comments', function (Blueprint $table) {
            $table->string('author')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_pending_comments', function (Blueprint $table) {
            $table->dropColumn('author');
        });
    }
};
