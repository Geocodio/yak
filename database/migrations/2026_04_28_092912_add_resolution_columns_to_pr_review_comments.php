<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pr_review_comments', function (Blueprint $table): void {
            $table->enum('resolution_status', ['fixed', 'still_outstanding', 'untouched', 'withdrawn'])
                ->nullable()
                ->after('is_suggestion');
            $table->foreignId('resolved_in_review_id')
                ->nullable()
                ->after('resolution_status')
                ->constrained('pr_reviews')
                ->nullOnDelete();
            $table->unsignedBigInteger('resolution_reply_github_id')
                ->nullable()
                ->after('resolved_in_review_id');
        });
    }

    public function down(): void
    {
        Schema::table('pr_review_comments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resolved_in_review_id');
            $table->dropColumn(['resolution_status', 'resolution_reply_github_id']);
        });
    }
};
