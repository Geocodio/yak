<?php

use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Preview\ThreadPreviewActivity;
use App\Services\Threads\ThreadSandbox;

require_once __DIR__ . '/../../../Support/ThreadPreviewHelpers.php';

beforeEach(function () {
    config(['yak.threads.preview_activity_minutes' => 5]);
    $this->travelTo(now()->startOfSecond());
});

function pausedOperations(): array
{
    return array_column(app(SandboxManager::class)->calls, 'operation');
}

it('blocks pauseIfIdle for a request inside the window', function () {
    $thread = previewSandboxThread();
    app(ThreadPreviewActivity::class)->record($thread);

    $this->travel(4)->minutes();
    app(ThreadSandbox::class)->pauseIfIdle($thread);

    expect(pausedOperations())->toBe([])->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

it('pauses once the window has ended', function () {
    $thread = previewSandboxThread();
    app(ThreadPreviewActivity::class)->record($thread);

    $this->travel(6)->minutes();
    app(ThreadSandbox::class)->pauseIfIdle($thread);

    expect(pausedOperations())->toBe(['pause']);
});

it('refreshes last_activity_at so the stop and destroy clocks restart', function () {
    $thread = previewSandboxThread(['last_activity_at' => now()->subHours(3)]);

    app(ThreadPreviewActivity::class)->record($thread);

    expect($thread->fresh()->last_activity_at->equalTo(now()))->toBeTrue();
});

it('counts a child sharing the container as activity', function () {
    $parent = previewSandboxThread();
    $child = Thread::factory()->child($parent)->create(['sandbox_container' => $parent->sandbox_container, 'sandbox_state' => ThreadSandboxState::Running]);
    app(ThreadPreviewActivity::class)->record($child);

    expect(app(ThreadPreviewActivity::class)->isActive($parent))->toBeTrue();
});

it('throttles writes of the preview column', function () {
    $thread = previewSandboxThread();
    $activity = app(ThreadPreviewActivity::class);

    $activity->record($thread);
    $first = $thread->fresh()->preview_last_request_at;
    $this->travel(10)->seconds();
    $activity->record($thread);

    expect($thread->fresh()->preview_last_request_at->equalTo($first))->toBeTrue();
});

it('never pauses a running turn regardless of activity', function () {
    $thread = previewSandboxThread();
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);

    $this->travel(30)->minutes();
    app(ThreadSandbox::class)->pauseIfIdle($thread);

    expect(pausedOperations())->toBe([]);
});
