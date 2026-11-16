<?php

use App\Enums\TeleportState;
use App\Enums\TeleportStep;
use App\Models\HeldThreadEvent;
use App\Models\Teleport;
use App\Models\Thread;

it('stores a ulid and defaults expires_at', function () {
    $teleport = Teleport::factory()->up()->create();

    expect($teleport->id)->toBeString()->toHaveLength(26)
        ->and($teleport->expires_at)->not->toBeNull()
        ->and($teleport->state)->toBe(TeleportState::Open);
});

it('marks terminal states', function (TeleportState $state, bool $terminal) {
    expect($state->isTerminal())->toBe($terminal);
})->with([
    [TeleportState::Open, false],
    [TeleportState::WaitingForTurn, false],
    [TeleportState::Preparing, false],
    [TeleportState::Ready, false],
    [TeleportState::Completed, true],
    [TeleportState::Failed, true],
    [TeleportState::Cancelled, true],
    [TeleportState::Expired, true],
]);

it('merges progress and pushes expiry on touchCall', function () {
    $teleport = Teleport::factory()->up()->create(['expires_at' => now()->subMinute()]);
    $teleport->progressStep(TeleportStep::FetchBranch, 'running');
    $teleport->progressStep(TeleportStep::FetchBranch, 'done');
    $teleport->progressStep(TeleportStep::Setup, 'running');
    $teleport->touchCall();

    expect($teleport->fresh()->progress)->toBe([
        ['step' => 'fetch_branch', 'status' => 'done'],
        ['step' => 'setup', 'status' => 'running'],
    ])->and($teleport->fresh()->expires_at->isFuture())->toBeTrue()
        ->and($teleport->fresh()->last_call_at)->not->toBeNull();
});

it('toApi matches the contract keys', function () {
    $teleport = Teleport::factory()->down()->inState(TeleportState::Failed)->create(['failure_code' => 'push_failed', 'failure_message' => 'Push failed.']);
    $api = $teleport->toApi();

    expect(array_keys($api))->toBe(['teleport_id', 'thread_id', 'direction', 'state', 'device_id', 'created_at', 'updated_at', 'expires_at', 'poll_after_seconds', 'progress', 'failure'])
        ->and($api['direction'])->toBe('down')
        ->and($api['state'])->toBe('failed')
        ->and($api['poll_after_seconds'])->toBe(2)
        ->and($api['failure'])->toBe(['code' => 'push_failed', 'message' => 'Push failed.']);
});

it('openTeleport ignores terminal teleports', function () {
    $thread = Thread::factory()->create();
    Teleport::factory()->up()->inState(TeleportState::Completed)->create(['thread_id' => $thread->id]);
    expect($thread->openTeleport())->toBeNull();

    $open = Teleport::factory()->down()->create(['thread_id' => $thread->id]);
    expect($thread->openTeleport()?->id)->toBe($open->id);
});

it('counts held events on the thread', function () {
    $thread = Thread::factory()->create();
    HeldThreadEvent::factory()->create(['thread_id' => $thread->id]);
    HeldThreadEvent::factory()->create(['thread_id' => $thread->id, 'delivered_at' => now()]);

    expect($thread->heldEvents()->whereNull('delivered_at')->count())->toBe(1);
});
