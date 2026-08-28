<?php

use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('artifacts carry a nullable indexed role column', function () {
    expect(Schema::hasColumn('artifacts', 'role'))->toBeTrue();
});

test('the backfill maps legacy types and filenames onto roles', function () {
    $task = YakTask::factory()->create();

    // Insert straight through the query builder so the migration's own
    // mapping is what's under test, not the factory's role defaults.
    $rows = [
        ['type' => 'video_cut', 'filename' => 'reviewer-cut.mp4', 'expected' => 'cut'],
        ['type' => 'video_cut', 'filename' => 'director-cut.mp4', 'expected' => null],
        ['type' => 'video_thumbnail', 'filename' => 'reviewer-cut-thumbnail.jpg', 'expected' => 'thumbnail'],
        ['type' => 'screenshot', 'filename' => 'description.png', 'expected' => 'screenshot'],
        ['type' => 'video', 'filename' => 'walkthrough.webm', 'expected' => 'raw'],
        ['type' => 'video', 'filename' => 'director-cut.webm', 'expected' => null],
        ['type' => 'file', 'filename' => 'storyboard.json', 'expected' => 'manifest'],
        ['type' => 'file', 'filename' => 'notes.txt', 'expected' => null],
        ['type' => 'research', 'filename' => 'research.html', 'expected' => null],
    ];

    $ids = [];
    foreach ($rows as $index => $row) {
        $ids[$index] = DB::table('artifacts')->insertGetId([
            'yak_task_id' => $task->id,
            'type' => $row['type'],
            'filename' => $row['filename'],
            'disk_path' => "{$task->id}/{$row['filename']}",
            'size_bytes' => 10,
            'role' => null,
            'created_at' => now(),
        ]);
    }

    // The mapping lives in Artifact::backfillRoles(); the migration is
    // just its first caller, so the test exercises the helper directly.
    Artifact::backfillRoles();

    foreach ($rows as $index => $row) {
        expect(Artifact::find($ids[$index])->role)
            ->toBe($row['expected'], "row {$index} ({$row['filename']})");
    }
});
