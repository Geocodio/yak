<?php

use App\Enums\TeleportState;
use App\Models\Device;
use App\Models\Teleport;
use App\Services\Teleport\ThreadNotOwnedByYak;
use App\Services\Threads\ThreadTurns;

require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

it('refuses to start a turn on a device-owned thread', function () {
    [$thread, $task] = threadWithTask();
    fakeTurnCollaborators();
    $thread->forceFill(['owner_side' => 'device', 'owner_device_id' => Device::factory()->create()->id])->save();

    expect(fn () => app(ThreadTurns::class)->start($task, 'go', null, null))->toThrow(ThreadNotOwnedByYak::class);
    expect($thread->runs()->count())->toBe(0);
});

it('refuses to start a turn while a down teleport is preparing', function () {
    [$thread, $task] = threadWithTask();
    fakeTurnCollaborators();
    Teleport::factory()->down()->inState(TeleportState::Preparing)->create(['thread_id' => $thread->id]);

    expect(fn () => app(ThreadTurns::class)->start($task, 'go', null, null))->toThrow(ThreadNotOwnedByYak::class);
});

it('starts a turn after the teleport completed', function () {
    [$thread, $task] = threadWithTask();
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner');
    $fakes['commands']->shouldReceive('turn');
    Teleport::factory()->up()->inState(TeleportState::Completed)->create(['thread_id' => $thread->id]);

    expect(app(ThreadTurns::class)->start($task, 'go', null, null)->thread_id)->toBe($thread->id);
});

it('refuses to steer a device-owned thread', function () {
    [$thread] = threadWithTask();
    fakeTurnCollaborators();
    $thread->forceFill(['owner_side' => 'device', 'owner_device_id' => Device::factory()->create()->id])->save();

    expect(fn () => app(ThreadTurns::class)->steer($thread, 'hey', null, null))->toThrow(ThreadNotOwnedByYak::class);
});
