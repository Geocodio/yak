<?php

use App\Models\User;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;

test('a follow-up keeps the responsible person and records its own starter', function () {
    $parent = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/org/repo/pull/7',
        'pr_number' => 7,
        'branch_name' => 'yak/fix-7',
        'author_name' => 'Original Starter',
        'responsible_name' => 'Owner Person',
    ]);

    $child = app(FollowUpTaskFactory::class)->create($parent, 'Also handle the empty state', 'slack', authorName: 'Follow Upper');

    expect($child)->not->toBeNull()
        ->and($child->author_name)->toBe('Follow Upper')
        ->and($child->responsible_name)->toBe('Owner Person');
});

test('a follow-up on a chain with no responsible person makes its starter responsible', function () {
    $parent = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/org/repo/pull/8',
        'pr_number' => 8,
        'branch_name' => 'yak/fix-8',
        'author_name' => null,
        'responsible_name' => null,
    ]);

    $child = app(FollowUpTaskFactory::class)->create($parent, 'Handle the empty state', 'slack', authorName: 'Follow Upper');

    expect($child->responsible_name)->toBe('Follow Upper');
});

test('a follow-up keeps the starter and responsible user and records who replied in Slack', function () {
    $starter = User::factory()->create();
    $owner = User::factory()->create();
    $parent = YakTask::factory()->success()->create([
        'pr_url' => 'https://github.com/org/repo/pull/9',
        'pr_number' => 9,
        'branch_name' => 'yak/fix-9',
        'started_by_user_id' => $starter->id,
        'responsible_user_id' => $owner->id,
        'slack_user_id' => 'U_REQUESTER',
    ]);

    $child = app(FollowUpTaskFactory::class)->create($parent, 'Rename the flag', 'slack', authorName: 'Replier', slackFollowUpUserId: 'U_REPLIER');

    expect($child->started_by_user_id)->toBe($starter->id)
        ->and($child->responsible_user_id)->toBe($owner->id)
        ->and($child->slack_user_id)->toBe('U_REQUESTER')
        ->and($child->slack_follow_up_user_id)->toBe('U_REPLIER');
});
