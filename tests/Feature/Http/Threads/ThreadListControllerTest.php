<?php

use App\Models\Thread;
use App\Models\ThreadView;
use App\Models\User;
use App\Models\YakTask;

it('returns the list as json with the filters applied', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->paused()->create(['created_by_user_id' => $user->id, 'repo' => 'acme/web', 'last_activity_at' => now()]);
    YakTask::factory()->create(['thread_id' => $thread->id, 'started_by_user_id' => $user->id]);
    Thread::factory()->paused()->create(['repo' => 'acme/other', 'last_activity_at' => now()]);

    $this->actingAs($user)->getJson(route('threads.index', ['filter' => 'mine', 'status' => 'paused']))
        ->assertOk()
        ->assertJsonCount(1, 'threads')
        ->assertJsonPath('threads.0.id', $thread->id)
        ->assertJsonPath('threads.0.repo', 'acme/web')
        ->assertJsonPath('needsYou', []);

    $this->actingAs($user)->getJson(route('threads.index', ['filter' => 'everyone']))->assertJsonCount(2, 'threads');
    $this->actingAs($user)->getJson(route('threads.index', ['status' => 'bogus']))->assertUnprocessable();
});

it('marks the thread seen when the task page opens', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->paused()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    $this->actingAs($user)->get(route('tasks.show', $task))->assertOk();

    expect(ThreadView::query()->where(['user_id' => $user->id, 'thread_id' => $thread->id])->count())->toBe(1);
});
