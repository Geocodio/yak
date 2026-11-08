<?php

use App\Enums\ThreadSessionKind;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadFactory;

it('creates one thread for a root task', function () {
    $user = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'is_default' => true]);
    $task = YakTask::factory()->create(['repo' => 'acme', 'started_by_user_id' => $user->id]);

    $thread = app(ThreadFactory::class)->forTask($task);

    expect($thread->repo)->toBe('acme')
        ->and($thread->created_by_user_id)->toBe($user->id)
        ->and($thread->claude_account_id)->toBe($account->id)
        ->and($thread->repositories)->toHaveCount(1)
        ->and($thread->repositories->first()->role)->toBe('primary')
        ->and($task->fresh()->thread_id)->toBe($thread->id);
});

it("attaches a follow-up to its parent's thread", function () {
    $thread = Thread::factory()->create();
    $root = YakTask::factory()->create(['thread_id' => $thread->id]);
    $followUp = YakTask::factory()->create(['parent_task_id' => $root->id]);

    expect(app(ThreadFactory::class)->forTask($followUp)->is($thread))->toBeTrue()
        ->and($followUp->fresh()->thread_id)->toBe($thread->id)
        ->and(Thread::count())->toBe(1);
});

it('creates and seeds a thread for a follow-up on a task without one', function () {
    $root = YakTask::factory()->create(['session_id' => 'legacy-session']);
    $followUp = YakTask::factory()->create(['parent_task_id' => $root->id]);

    $thread = app(ThreadFactory::class)->forTask($followUp);

    expect($root->fresh()->thread_id)->toBe($thread->id)
        ->and($thread->sessions)->toHaveCount(1)
        ->and($thread->sessions->first()->session_id)->toBe('legacy-session')
        ->and($thread->sessions->first()->kind)->toBe(ThreadSessionKind::Main);
});

it('returns the existing thread on a second call', function () {
    $task = YakTask::factory()->create();
    $factory = app(ThreadFactory::class);

    expect($factory->forTask($task)->is($factory->forTask($task)))->toBeTrue()
        ->and(Thread::count())->toBe(1);
});
