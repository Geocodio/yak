<?php

use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadCloser;

function openThreadWithSandbox(array $attributes = []): Thread
{
    $thread = Thread::factory()->create([
        'sandbox_container' => 'yak-thread-' . fake()->unique()->numberBetween(1, 99999),
        'sandbox_state' => ThreadSandboxState::Running,
        ...$attributes,
    ]);
    app(BrokerStreams::class)->register("threads:{$thread->id}");

    return $thread;
}

it('destroys the sandbox, marks the thread closed and forgets its channel', function () {
    $thread = openThreadWithSandbox();

    app(ThreadCloser::class)->close($thread, 'pr_merged');

    $thread->refresh();
    expect($thread->status)->toBe(ThreadStatus::Closed)
        ->and($thread->close_reason)->toBe('pr_merged')
        ->and($thread->closed_at)->not->toBeNull()
        ->and($thread->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and(app(SandboxManager::class)->state($thread))->toBe(ThreadSandboxState::Destroyed)
        ->and(app(BrokerStreams::class)->channels('threads:'))->not->toContain("threads:{$thread->id}");
});

it('is idempotent', function () {
    $thread = openThreadWithSandbox();
    $closer = app(ThreadCloser::class);

    $closer->close($thread, 'pr_closed');
    $closedAt = $thread->refresh()->closed_at;
    $this->travel(1)->hour();
    $closer->close($thread, 'idle');

    expect($thread->refresh()->close_reason)->toBe('pr_closed')
        ->and($thread->closed_at->equalTo($closedAt))->toBeTrue();
});

it('keeps a device-owned thread open when it only went idle', function () {
    $thread = openThreadWithSandbox(['owner_side' => ThreadOwnerSide::Device]);

    app(ThreadCloser::class)->close($thread, 'idle');

    expect($thread->refresh()->status)->toBe(ThreadStatus::Open);

    app(ThreadCloser::class)->close($thread, 'pr_merged');

    expect($thread->refresh()->status)->toBe(ThreadStatus::Closed);
});
