<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('repo');
            $table->string('version', 64);
            $table->json('profile');
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('repo');
            $table->unique(['repo', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_profiles');
    }
};
