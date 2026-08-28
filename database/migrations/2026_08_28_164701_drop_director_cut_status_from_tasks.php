<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v3 renders one walkthrough per task. The Director's Cut tier, its
     * job and its UI are gone, so the status column has no writer left.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('director_cut_status');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('director_cut_status')->nullable()->after('status');
        });
    }
};
