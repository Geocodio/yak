<?php

use App\Enums\TaskStatus;
use App\Models\YakTask;

test('the backfill stamps started_at on finished follow-ups and leaves everything else alone', function () {
    $root = YakTask::factory()->create(['status' => TaskStatus::Success, 'started_at' => null, 'completed_at' => now()]);

    $finished = YakTask::factory()->create([
        'parent_task_id' => $root->id,
        'status' => TaskStatus::Success,
        'started_at' => null,
        'completed_at' => now(),
    ]);

    $inFlight = YakTask::factory()->create([
        'parent_task_id' => $root->id,
        'status' => TaskStatus::Pending,
        'started_at' => null,
        'completed_at' => null,
    ]);

    (require base_path('database/migrations/2026_08_28_132235_backfill_started_at_on_follow_up_runs.php'))->up();

    expect($finished->fresh()->started_at?->toDateTimeString())->toBe($finished->created_at->toDateTimeString())
        // A run still queued has not started, and the root is not a follow-up.
        ->and($inFlight->fresh()->started_at)->toBeNull()
        ->and($root->fresh()->started_at)->toBeNull();
});
