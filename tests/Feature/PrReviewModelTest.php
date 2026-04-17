<?php

use App\Models\PrReview;
use App\Models\YakTask;

it('creates a pr_review row with required fields', function () {
    $task = YakTask::factory()->create();

    $review = PrReview::create([
        'yak_task_id' => $task->id,
        'repo' => 'geocodio/api',
        'pr_number' => 42,
        'pr_url' => 'https://github.com/geocodio/api/pull/42',
        'github_review_id' => 12345,
        'commit_sha_reviewed' => 'abc123',
        'review_scope' => 'full',
        'summary' => 'Adds retry on timeout.',
        'verdict' => 'Approve with suggestions',
        'submitted_at' => now(),
    ]);

    expect($review->yak_task_id)->toBe($task->id)
        ->and($review->review_scope)->toBe('full')
        ->and($review->dismissed_at)->toBeNull()
        ->and($review->incremental_base_sha)->toBeNull();
});

it('has a belongsTo task relation', function () {
    $task = YakTask::factory()->create();
    $review = PrReview::factory()->for($task, 'task')->create();

    expect($review->task->id)->toBe($task->id);
});
