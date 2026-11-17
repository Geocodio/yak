<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadList;

test('visiting seen clears the unread mark', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create([
        'yak_task_id' => $task->id,
        'thread_id' => $thread->id,
        'turn_state' => TurnState::Completed,
        'finished_at' => now()->subHour(),
    ]);

    expect(ThreadList::for($user)['threads'][0]['isUnread'])->toBeTrue();

    $this->actingAs($user)->postJson(route('threads.seen', $thread))->assertNoContent();

    $list = ThreadList::for($user);
    expect($list['threads'][0]['isUnread'])->toBeFalse();
});
