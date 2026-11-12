<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
        });

        $configuredAdminIds = array_filter(array_map('intval', explode(',', (string) env('YAK_SYSTEM_ACCOUNT_ADMIN_IDS', ''))));

        if ($configuredAdminIds === []) {
            $firstUserId = DB::table('users')->min('id');

            if ($firstUserId !== null) {
                DB::table('users')->where('id', $firstUserId)->update(['is_admin' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
