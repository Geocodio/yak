<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Threads opened for a failing CI job link to its GitHub Actions run, so
     * their source is GitHub.
     */
    public function up(): void
    {
        DB::table('threads')
            ->where('source', 'dashboard')
            ->where('source_url', 'like', 'https://github.com/%/actions/runs/%')
            ->update(['source' => 'github']);
    }
};
