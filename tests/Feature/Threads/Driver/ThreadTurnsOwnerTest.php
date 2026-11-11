<?php

use App\Models\User;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../../../Support/ThreadTurnsHelpers.php';

beforeEach(function () {
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn', 'setModel');
});

it('sets owner_user_id to the driver of the first turn when the owner is null', function () {
    [$thread, $task] = threadWithTask();
    $user = User::factory()->create();

    app(ThreadTurns::class)->start($task, 'x', $user, null);

    expect($thread->refresh()->owner_user_id)->toBe($user->id);
});

it('keeps an existing owner when another person drives a later turn', function () {
    [$thread, $task] = threadWithTask();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $thread->forceFill(['owner_user_id' => $owner->id])->save();

    app(ThreadTurns::class)->start($task, 'x', $other, null);

    expect($thread->refresh()->owner_user_id)->toBe($owner->id);
});

it('leaves the owner null when the driver is null', function () {
    [$thread, $task] = threadWithTask();

    app(ThreadTurns::class)->start($task, 'x', null, null);

    expect($thread->refresh()->owner_user_id)->toBeNull();
});

it('sets the owner from a persons steering message when the thread has none', function () {
    [$thread] = threadWithTask();
    $user = User::factory()->create();
    $other = User::factory()->create();

    app(ThreadTurns::class)->steer($thread, 'hello', $user, null);
    app(ThreadTurns::class)->steer($thread, 'again', $other, null);

    expect($thread->refresh()->owner_user_id)->toBe($user->id);
});
