<?php

use App\Enums\ThreadBlockReason;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use Inertia\Testing\AssertableInertia as Assert;

it('exposes messages, composer and notice props', function () {
    $thread = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::UsageLimit]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'description' => 'Fix the bug']);
    $run = TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => 'completed']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id]);

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Threads/Show')
        ->has('messages', 1)
        ->where('messages.0.text', 'Fix the bug')
        ->where('composer.state', 'idle')
        ->where('notice.kind', 'usage_limit'));
});

it('sends a null notice when nothing blocks', function () {
    $thread = Thread::factory()->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->has('messages', 0)
        ->where('notice', null));
});

it('still 404s a hidden thread', function () {
    $thread = Thread::factory()->private()->create();

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")->assertNotFound();
});

it('leaves the wave 0 props unchanged', function () {
    $thread = Thread::factory()->create(['title' => 'Fix the cache', 'branch' => 'yak/cache']);

    $this->actingAs(User::factory()->create())->get("/threads/{$thread->public_id}")->assertInertia(fn (Assert $page) => $page
        ->where('thread.id', $thread->public_id)
        ->where('thread.title', 'Fix the cache')
        ->where('thread.branch', 'yak/cache')
        ->where('thread.visibility', 'team')
        ->where('thread.ownerSide', 'yak')
        ->has('thread.repositories')
        ->where('canDrive', true));
});
