<?php

use App\Enums\DiffFileStatus;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('stores one diff per thread and cascades on thread delete', function () {
    $thread = Thread::factory()->create();
    $diff = ThreadDiff::factory()->for($thread)->withFiles(3)->create();

    expect($thread->diff->files)->toHaveCount(3)
        ->and($thread->diff->files->pluck('path')->all())->toBe($thread->diff->files->pluck('path')->sort()->values()->all())
        ->and($diff->fresh()->files_count)->toBe(3);

    expect(fn () => ThreadDiff::factory()->for($thread)->create())->toThrow(QueryException::class);

    $thread->delete();

    expect(DB::table('thread_diffs')->count())->toBe(0)
        ->and(DB::table('thread_diff_files')->count())->toBe(0);
});

it('casts file status to DiffFileStatus', function () {
    $file = ThreadDiffFile::factory()->create(['status' => 'renamed']);

    expect($file->fresh()->status)->toBe(DiffFileStatus::Renamed);
});

it('builds binary and too large file states', function () {
    expect(ThreadDiffFile::factory()->binary()->create()->is_binary)->toBeTrue()
        ->and(ThreadDiffFile::factory()->tooLarge()->create()->is_too_large)->toBeTrue();
});
