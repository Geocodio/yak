<?php

use App\Enums\TaskRunKind;
use App\Http\Resources\ThreadMessagesData;
use App\Models\ThreadEvent;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

function fakeTurnCommands(): void
{
    $commands = fakeTurnCollaborators()['commands'];
    $commands->shouldReceive('startRunner', 'turn', 'setModel')->andReturnNull();
}

it('stores the given run kind', function () {
    fakeTurnCommands();
    [$thread, $task] = threadWithTask();

    $run = app(ThreadTurns::class)->start($task, 'p', null, null, TaskRunKind::RepositoryEvent);

    expect($run->kind)->toBe(TaskRunKind::RepositoryEvent);
});

it('derives the kind when none is given', function () {
    fakeTurnCommands();
    [$thread, $task] = threadWithTask();

    $run = app(ThreadTurns::class)->start($task, 'p', null, null);

    expect($run->kind)->toBe(TaskRunKind::Initial);
});

it("shows the person's typed message, not the task description, for a composer turn", function () {
    fakeTurnCommands();
    [$thread, $task] = threadWithTask();
    $task->update(['description' => '']);

    $run = app(ThreadTurns::class)->start($task, 'full prompt with notes', null, null, shownText: 'Please set up the preview');

    $messages = ThreadMessagesData::for($thread);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['text'])->toBe('Please set up the preview')
        ->and($run->prompt)->toBe('full prompt with notes');
});

it('places each message at the point Claude took it in, not with earlier history', function () {
    fakeTurnCommands();
    [$thread, $task] = threadWithTask();

    $first = app(ThreadTurns::class)->start($task, 'one', null, null, shownText: 'First');
    $firstUuid = (string) $first->prompt_uuid;
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 4, 'type' => 'message.state', 'payload' => ['uuid' => $firstUuid, 'state' => 'started']]);
    ThreadEvent::factory()->create(['thread_id' => $thread->id, 'sequence' => 30, 'type' => 'turn', 'payload' => ['turnId' => $first->id + 1, 'state' => 'running']]);

    $messages = ThreadMessagesData::for($thread);

    expect($messages[0]['readAfterSequence'])->toBe(4);
});
