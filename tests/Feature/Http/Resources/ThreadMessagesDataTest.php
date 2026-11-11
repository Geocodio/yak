<?php

use App\Enums\TurnMessageState;
use App\Http\Resources\ThreadMessagesData;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;

function threadWithRun(): array
{
    $thread = Thread::factory()->create();
    $driver = User::factory()->create(['name' => 'Ada Lovelace']);
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'description' => 'Fix the login bug']);
    $run = TaskRun::factory()->create([
        'yak_task_id' => $task->id,
        'thread_id' => $thread->id,
        'driver_user_id' => $driver->id,
        'prompt' => 'ASSEMBLED PROMPT with system notes',
        'model' => 'opus',
    ]);

    return [$thread, $task, $run];
}

it('lists prompts and steers in send order', function () {
    [$thread, $task, $run] = threadWithRun();
    $steer = PendingSteeringMessage::query()->create([
        'root_task_id' => $task->id, 'text' => 'Also check the logout', 'source' => 'thread',
        'mode' => 'steer', 'author_name' => 'Grace Hopper', 'uuid' => 'steer-uuid', 'model' => 'sonnet',
    ]);
    ThreadTurnMessage::factory()->steer()->create(['task_run_id' => $run->id, 'uuid' => 'steer-uuid', 'pending_steering_message_id' => $steer->id, 'created_at' => now()->addMinute()]);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => 'prompt-uuid', 'created_at' => now()]);

    $messages = ThreadMessagesData::for($thread);

    expect(array_column($messages, 'uuid'))->toBe(['prompt-uuid', 'steer-uuid'])
        ->and($messages[0])->toMatchArray(['authorName' => 'Ada Lovelace', 'authorInitials' => 'AL', 'model' => 'opus', 'readAfterSequence' => null, 'attachmentIds' => []])
        ->and($messages[1])->toMatchArray(['text' => 'Also check the logout', 'authorName' => 'Grace Hopper', 'authorInitials' => 'GH', 'model' => 'sonnet']);
});

it('maps turn message states to pending, read and cancelled', function (TurnMessageState $state, string $expected) {
    [$thread, , $run] = threadWithRun();
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'state' => $state]);

    expect(ThreadMessagesData::for($thread)[0]['state'])->toBe($expected);
})->with([
    'sent' => [TurnMessageState::Sent, 'pending'],
    'queued' => [TurnMessageState::Queued, 'pending'],
    'started' => [TurnMessageState::Started, 'read'],
    'completed' => [TurnMessageState::Completed, 'read'],
    'cancelled' => [TurnMessageState::Cancelled, 'cancelled'],
]);

it("uses the person's words, not the assembled prompt", function () {
    [$thread, , $run] = threadWithRun();
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id]);

    expect(ThreadMessagesData::for($thread)[0]['text'])->toBe('Fix the login bug');
});

it('leaves out other threads', function () {
    [, , $run] = threadWithRun();
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id]);

    expect(ThreadMessagesData::for(Thread::factory()->create()))->toBe([]);
});
