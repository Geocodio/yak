<?php

use App\Enums\ThreadSandboxState;
use App\Jobs\Threads\StartThreadPreviewJob;
use App\Models\Thread;
use App\Models\User;
use App\Services\DeploymentContainerManager;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Preview\ThreadPreviewWaker;
use App\Services\Threads\SandboxCapacity;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

require_once __DIR__ . '/../../../Support/ThreadPreviewHelpers.php';

beforeEach(function () {
    $this->mock(DeploymentContainerManager::class)->shouldReceive('resolveContainerIp')->andReturn('10.0.0.7');
});

function wakeOperations(): array
{
    return array_column(app(SandboxManager::class)->calls, 'operation');
}

it('is ready without sleeping when the dev server is healthy', function () {
    Sleep::fake();
    $thread = previewSandboxThread();
    queueHealthyPreview();

    $outcome = app(ThreadPreviewWaker::class)->wake($thread, 10);

    expect($outcome->state)->toBe('ready')->and($outcome->host)->toBe('10.0.0.7')->and($outcome->port)->toBe(3000);
    Sleep::assertNeverSlept();
});

it('resumes a paused sandbox within the hold', function () {
    $thread = Thread::factory()->paused()->create();
    queueHealthyPreview();

    $outcome = app(ThreadPreviewWaker::class)->wake($thread, 10);

    expect($outcome->state)->toBe('ready')->and(wakeOperations())->toContain('resume');
});

it('starts a stopped sandbox once for five concurrent calls', function () {
    $thread = Thread::factory()->stopped()->create();
    queueHealthyPreview(6);

    $outcomes = collect(range(1, 5))->map(fn () => app(ThreadPreviewWaker::class)->wake(Thread::withoutGlobalScopes()->find($thread->id), 10));

    expect($outcomes->pluck('state')->unique()->all())->toBe(['ready'])
        ->and(array_count_values(wakeOperations())['start'])->toBe(1);
});

it('returns waking past the hold limit', function () {
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);
    $this->travelTo(now()->startOfSecond());
    $thread = previewSandboxThread();
    queueSandboxOutput("port: 3000\nhealth_probe_path: /up\ncold_start: bin/serve\n");
    queueSandboxOutput('refused', 7);

    $outcome = app(ThreadPreviewWaker::class)->wake($thread, 2);

    expect($outcome->state)->toBe('waking')->and($outcome->steps)->toContain('Starting the dev server');
    Sleep::assertSleptTimes(8);
    Queue::assertPushed(StartThreadPreviewJob::class);
});

it('returns waiting_for_slot at the cap with nothing to evict', function () {
    config(['yak.threads.awake_cap' => 1]);
    previewSandboxThread();
    $thread = Thread::factory()->stopped()->create();

    expect(app(ThreadPreviewWaker::class)->wake($thread, 1)->state)->toBe('waiting_for_slot');
});

it('wakes on the owner share even when a teammate asks', function () {
    $owner = User::factory()->create();
    $thread = Thread::factory()->stopped()->create(['owner_user_id' => $owner->id]);
    queueHealthyPreview(2);

    $outcome = app(ThreadPreviewWaker::class)->wake($thread, 10);

    expect($outcome->state)->toBe('ready')->and(app(SandboxCapacity::class)->awakeCountFor($owner->id))->toBe(1);
});

it('does not restore a destroyed sandbox of an open thread', function () {
    $thread = Thread::factory()->destroyed()->create();

    $outcome = app(ThreadPreviewWaker::class)->wake($thread, 10);

    expect($outcome->state)->toBe('destroyed')->and(wakeOperations())->toBe([])
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed);
});

it('never wakes a closed or local machine thread', function (string $state, string $factoryState) {
    $thread = Thread::factory()->stopped()->{$factoryState}()->create();

    expect(app(ThreadPreviewWaker::class)->wake($thread, 10)->state)->toBe($state)->and(wakeOperations())->toBe([]);
})->with([['closed', 'closed'], ['local_machine', 'ownedByDevice']]);
