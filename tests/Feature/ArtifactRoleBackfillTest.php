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

test('the role migration can be re-run after a partial backfill', function () {
    $migration = require database_path('migrations/2026_08_28_171900_add_role_to_artifacts_table.php');

    // Simulates a backfill that died partway: the column is already there,
    // but this row never got its role written.
    $task = YakTask::factory()->create();
    DB::table('artifacts')->insert([
        'yak_task_id' => $task->id,
        'type' => 'video_cut',
        'filename' => 'walkthrough.mp4',
        'disk_path' => "{$task->id}/walkthrough.mp4",
        'size_bytes' => 10,
        'role' => null,
        'created_at' => now(),
    ]);

    $migration->up();

    expect(Artifact::where('filename', 'walkthrough.mp4')->sole()->role)->toBe('cut');
});
