<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pr_reviews', function (Blueprint $table): void {
            $table->json('risk_assessment')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pr_reviews', function (Blueprint $table): void {
            $table->dropColumn('risk_assessment');
        });
    }
};
