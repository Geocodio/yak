<?php

use App\Enums\RepositoryRole;
use App\Enums\ThreadSessionKind;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\SessionStore\SessionSync;
use App\Services\Threads\ThreadFactory;

beforeEach(function () {
    $this->mock(SessionSync::class)->shouldReceive('seedFromLegacyTranscript')->andReturn(false)->byDefault();
});

it('starts a thread with a primary repository for a root task', function () {
    $user = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'is_default' => true]);
    $task = YakTask::factory()->create(['repo' => 'acme/web', 'started_by_user_id' => $user->id, 'thread_id' => null, 'session_id' => null]);

    $thread = app(ThreadFactory::class)->forTask($task);

    expect($thread->repo)->toBe('acme/web')
        ->and($thread->created_by_user_id)->toBe($user->id)
        ->and($thread->claude_account_id)->toBe($account->id)
        ->and($thread->repositories()->count())->toBe(1)
        ->and($thread->primaryRepository->role)->toBe(RepositoryRole::Primary)
        ->and($task->refresh()->thread_id)->toBe($thread->id);
});

it('puts a follow-up in the thread of its root', function () {
    $root = YakTask::factory()->create(['thread_id' => null]);
    $factory = app(ThreadFactory::class);
    $thread = $factory->forTask($root);
    $followUp = YakTask::factory()->create(['parent_task_id' => $root->id, 'repo' => $root->repo, 'thread_id' => null]);

    expect($factory->forTask($followUp)->id)->toBe($thread->id)
        ->and($followUp->refresh()->thread_id)->toBe($thread->id)
        ->and(Thread::query()->count())->toBe(1);
});

it('moves a task whose thread is closed into a new thread without a session', function () {
    $root = YakTask::factory()->create(['thread_id' => null, 'session_id' => null]);
    $factory = app(ThreadFactory::class);
    $old = $factory->forTask($root);
    $old->update(['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'idle']);

    $new = $factory->forTask($root->refresh());

    expect($new->id)->not->toBe($old->id)
        ->and($new->isOpen())->toBeTrue()
        ->and($new->sessions()->count())->toBe(0)
        ->and($root->refresh()->thread_id)->toBe($new->id);
});

it('creates one thread for repeated calls on the same root', function () {
    $root = YakTask::factory()->create(['thread_id' => null]);
    $factory = app(ThreadFactory::class);

    $first = $factory->forTask($root);
    $second = $factory->forTask(YakTask::query()->findOrFail($root->id)->forceFill(['thread_id' => null]));

    expect($second->id)->toBe($first->id)->and(Thread::query()->count())->toBe(1);
});

it('seeds the main session from a legacy root', function () {
    $root = YakTask::factory()->create(['thread_id' => null, 'session_id' => 'sess_legacy', 'branch_name' => 'yak/fix']);

    $thread = app(ThreadFactory::class)->forTask($root);

    expect($thread->activeSession()?->session_id)->toBe('sess_legacy')
        ->and($thread->activeSession()?->kind)->toBe(ThreadSessionKind::Main)
        ->and($thread->branch)->toBe('yak/fix');
});

it('continues a cleared thread on the same branch without a session', function () {
    $root = YakTask::factory()->create(['thread_id' => null, 'session_id' => null, 'branch_name' => 'yak/fix']);
    $factory = app(ThreadFactory::class);
    $previous = $factory->forTask($root);
    $previous->update(['branch' => 'yak/fix']);

    $next = $factory->continuing($previous, $root);

    expect($next->id)->not->toBe($previous->id)
        ->and($next->continued_from_thread_id)->toBe($previous->id)
        ->and($next->branch)->toBe('yak/fix')
        ->and($next->sessions()->count())->toBe(0)
        ->and($root->refresh()->thread_id)->toBe($next->id);
});
