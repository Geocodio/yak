<?php

use App\Models\Thread;
use Tests\Support\VisibilityWorld;

test('the visibility world builds a private graph owned by the owner', function () {
    $world = VisibilityWorld::build();

    expect($world->thread->isPrivate())->toBeTrue()
        ->and($world->child->root_thread_id)->toBe($world->thread->id)
        ->and($world->task->thread_id)->toBe($world->thread->id)
        ->and($world->deployment->yak_task_id)->toBe($world->task->id)
        ->and(config('claude-accounts.admin_user_ids'))->toBe([$world->admin->id])
        ->and(Thread::withoutGlobalScopes()->whereKey($world->missingId)->exists())->toBeFalse();
});

test('the team control world is visible to everyone', function () {
    $world = VisibilityWorld::forTeamControl();

    expect($world->thread->isPrivate())->toBeFalse();
});
