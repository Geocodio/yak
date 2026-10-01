<?php

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
