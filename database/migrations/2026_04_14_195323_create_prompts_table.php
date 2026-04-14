<?php

use App\Prompts\PromptDefinitions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->longText('content')->nullable();
            $table->boolean('is_customized')->default(false);
            $table->timestamps();
        });

        Schema::create('prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_id')->constrained('prompts')->cascadeOnDelete();
            $table->longText('content');
            $table->unsignedInteger('version');
            $table->timestamp('created_at')->nullable();

            $table->unique(['prompt_id', 'version']);
        });

        $now = now();

        $rows = [];
        foreach (array_keys(PromptDefinitions::all()) as $slug) {
            $rows[] = [
                'slug' => $slug,
                'content' => null,
                'is_customized' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('prompts')->insert($rows);
    }
};
