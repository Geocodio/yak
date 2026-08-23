<?php

use App\Models\FollowUpPendingComment;
use App\Models\YakTask;
use Illuminate\Support\Facades\Schema;

test('follow_up_pending_comments table has the expected columns', function () {
    foreach (['id', 'yak_task_id', 'pr_url', 'body', 'file', 'line', 'diff_hunk', 'github_comment_id', 'created_at'] as $col) {
        expect(Schema::hasColumn('follow_up_pending_comments', $col))->toBeTrue("missing column {$col}");
    }
});

test('a pending comment round-trips and belongs to a task', function () {
    $task = YakTask::factory()->create();

    $row = FollowUpPendingComment::create([
        'yak_task_id' => $task->id,
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'body' => 'handle the empty state',
        'file' => 'app/Foo.php',
        'line' => 42,
        'diff_hunk' => '@@ -1 +1 @@',
        'github_comment_id' => 555,
    ]);

    expect($row->fresh()->body)->toBe('handle the empty state')
        ->and($row->task->id)->toBe($task->id);
});
