<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linear_oauth_connections', function (Blueprint $table) {
            $table->id();
            $table->string('workspace_id')->unique();
            $table->string('workspace_name');
            $table->string('workspace_url_key')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at');
            $table->json('scopes')->nullable();
            $table->string('actor')->default('app');
            $table->string('app_user_id')->nullable();
            $table->string('installer_user_id')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linear_oauth_connections');
    }
};
