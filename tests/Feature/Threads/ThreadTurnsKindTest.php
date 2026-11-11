<?php

use App\Enums\TaskRunKind;
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
