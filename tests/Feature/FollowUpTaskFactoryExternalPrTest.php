<?php

use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use Illuminate\Support\Facades\Queue;

it('carries the external PR flag and the new summon thread onto a follow-up', function () {
    Queue::fake();

    $root = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/acme/web/pull/9',
        'branch_name' => 'feature/csv',
        'targets_external_pr' => true,
        'summon_review_comment_id' => 111,
    ]);

    $child = app(FollowUpTaskFactory::class)->create($root, 'fix it', 'github', 'alice', summonReviewCommentId: 222);

    expect($child->targets_external_pr)->toBeTrue()
        ->and($child->summon_review_comment_id)->toBe(222)
        ->and($child->branch_name)->toBe('feature/csv');
});

it('leaves Yak-owned follow-ups unflagged', function () {
    Queue::fake();

    $root = YakTask::factory()->success()->create(['pr_url' => 'https://github.com/acme/web/pull/9', 'branch_name' => 'yak/x']);

    $child = app(FollowUpTaskFactory::class)->create($root, 'fix it', 'github');

    expect($child->targets_external_pr)->toBeFalse()
        ->and($child->summon_review_comment_id)->toBeNull();
});
