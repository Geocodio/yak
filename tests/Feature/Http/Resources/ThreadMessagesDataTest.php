<?php

use App\Enums\TurnMessageState;
use App\Http\Resources\ThreadMessagesData;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Str;

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

it('renders markdown and lists the files sent with a steer', function () {
    [$thread, $task, $run] = threadWithRun();
    $steer = PendingSteeringMessage::query()->create([
        'root_task_id' => $task->id, 'text' => 'Use **v2**', 'source' => 'thread',
        'mode' => 'steer', 'author_name' => 'Grace Hopper', 'uuid' => 'steer-uuid', 'model' => 'sonnet',
    ]);
    $attachment = TaskAttachment::factory()->create(['yak_task_id' => null, 'pending_steering_message_id' => $steer->id]);
    ThreadTurnMessage::factory()->steer()->create(['task_run_id' => $run->id, 'uuid' => 'steer-uuid', 'pending_steering_message_id' => $steer->id]);

    $message = ThreadMessagesData::for($thread)[0];

    expect($message['bodyHtml'])->toContain('<strong>v2</strong>')
        ->and(array_column($message['attachments'], 'id'))->toBe([$attachment->id])
        ->and($message['attachmentIds'])->toBe([$attachment->id]);
});

it('lists a message typed while the thread waits as not read yet', function () {
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    PendingSteeringMessage::query()->create(['root_task_id' => $task->id, 'text' => 'Hello', 'source' => 'thread', 'mode' => 'queue', 'author_name' => 'Ada Lovelace', 'uuid' => (string) Str::uuid()]);

    $messages = ThreadMessagesData::for($thread);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['state'])->toBe('pending')
        ->and($messages[0]['text'])->toBe('Hello');
});

it('carries the request attachments of the first message', function () {
    [$thread, $task, $run] = threadWithRun();
    $task->update(['description' => 'Fix this [Image #1]']);
    $image = TaskAttachment::factory()->create(['yak_task_id' => $task->id, 'reference' => 'Image #1']);
    TaskAttachment::factory()->clarificationReply()->create(['yak_task_id' => $task->id, 'reference' => 'Image #2']);
    ThreadTurnMessage::factory()->create(['task_run_id' => $run->id, 'uuid' => (string) Str::uuid()]);

    $message = ThreadMessagesData::for($thread)[0];

    expect(array_column($message['attachments'], 'id'))->toBe([$image->id])
        ->and($message['attachmentIds'])->toBe([$image->id])
        ->and($message['bodyHtml'])->toContain('data-attachment-ref="Image #1"');
});
