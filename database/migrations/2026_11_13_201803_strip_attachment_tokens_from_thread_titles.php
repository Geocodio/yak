<?php

use App\Support\AttachmentReferences;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Threads named before titles dropped attachment tokens still read "... [Image #1]".
     */
    public function up(): void
    {
        DB::table('threads')
            ->where(fn ($query) => $query->where('title', 'like', '%[Image #%')->orWhere('title', 'like', '%[File #%'))
            ->orderBy('id')
            ->chunkById(200, function ($threads): void {
                foreach ($threads as $thread) {
                    $title = AttachmentReferences::stripFromText((string) $thread->title);
                    DB::table('threads')->where('id', $thread->id)->update(['title' => $title !== '' ? $title : $thread->title]);
                }
            });
    }

    public function down(): void {}
};
