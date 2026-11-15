<?php

use App\Enums\RestoreStepStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Jobs\Threads\RestoreThreadPreviewJob;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRestoreStep;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\Preview\ThreadPreviewStatus;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    Queue::fake();
    $this->owner = User::factory()->create(['name' => 'Test person']);
    $this->thread = appPreviewThread($this->owner, ['sandbox_state' => ThreadSandboxState::Destroyed]);
});

function previewRestorePath(Thread $thread): string
{
    return appThreadPath($thread, '/preview/restore');
}

it('starts a restore of a destroyed sandbox', function () {
    $response = $this->postJson(previewRestorePath($this->thread), [], phoneHeadersFor($this->owner))
        ->assertStatus(202)->assertExactJson(['status' => 'restoring']);

    ContractFixture::assertMatches('app', 'thread-preview-restore', $response->json());
    Queue::assertPushed(RestoreThreadPreviewJob::class, 1);
});

it('answers the same while a restore runs', function (Closure $arrange) {
    $arrange($this->thread);

    $this->postJson(previewRestorePath($this->thread), [], phoneHeadersFor($this->owner))
        ->assertStatus(202)->assertExactJson(['status' => 'restoring']);

    Queue::assertNothingPushed();
})->with([
    'a running step' => [fn (Thread $thread) => ThreadRestoreStep::factory()->create(['thread_id' => $thread->id, 'status' => RestoreStepStatus::Running, 'started_at' => now()->subMinutes(2)])],
    'a busy sandbox' => [fn (Thread $thread) => $thread->update(['lock_holder' => ThreadLockHolder::Sandbox])],
    'a running turn' => [function (Thread $thread) {
        $task = YakTask::factory()->create(['thread_id' => $thread->id]);
        TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    }],
]);

it('resumes when a running step is older than the lock', function () {
    ThreadRestoreStep::factory()->create(['thread_id' => $this->thread->id, 'status' => RestoreStepStatus::Running, 'started_at' => now()->subMinutes(11)]);

    $this->postJson(previewRestorePath($this->thread), [], phoneHeadersFor($this->owner))->assertStatus(202);

    Queue::assertPushed(RestoreThreadPreviewJob::class, 1);
});

it('dispatches once for two quick calls', function () {
    $headers = phoneHeadersFor($this->owner);

    $this->postJson(previewRestorePath($this->thread), [], $headers)->assertStatus(202);
    $this->postJson(previewRestorePath($this->thread), [], $headers)->assertStatus(202)->assertExactJson(['status' => 'restoring']);

    Queue::assertPushed(RestoreThreadPreviewJob::class, 1);
});

it('dispatches when the cache is down', function () {
    Exceptions::fake();
    Cache::partialMock()->shouldReceive('add')->with("thread-preview:restore:{$this->thread->id}", true, 300)->andThrow(new RuntimeException('cache down'));

    $this->postJson(previewRestorePath($this->thread), [], phoneHeadersFor($this->owner))->assertStatus(202);

    Queue::assertPushed(RestoreThreadPreviewJob::class, 1);
    Exceptions::assertReported(RuntimeException::class);
});

it('refuses when there is nothing to restore', function () {
    $thread = $this->thread;
    $thread->update(['sandbox_state' => ThreadSandboxState::Running]);
    cachePreviewState($thread, 'ready');

    $this->postJson(previewRestorePath($thread), [], phoneHeadersFor($this->owner))
        ->assertStatus(409)
        ->assertExactJson(['error' => 'nothing_to_restore', 'message' => 'The sandbox is still there, so there is nothing to restore.']);

    Queue::assertNothingPushed();
});

it('refuses a closed thread and a thread on a local machine', function () {
    $headers = phoneHeadersFor($this->owner);

    $this->thread->update(['status' => ThreadStatus::Closed]);
    $this->postJson(previewRestorePath($this->thread), [], $headers)
        ->assertStatus(409)->assertExactJson(['error' => 'thread_closed', 'message' => 'This thread is closed.']);

    $this->thread->update(['status' => ThreadStatus::Open, 'owner_side' => 'device']);
    $local = $this->thread;
    $this->postJson(previewRestorePath($local), [], $headers)
        ->assertStatus(409)->assertExactJson(['error' => 'thread_owned_by_device', 'message' => 'This thread runs on a local machine.']);

    Queue::assertNothingPushed();
});

it('lets a failed restore be restored again', function () {
    $this->thread->update(['blocked_reason' => ThreadBlockReason::RestoreFailed]);
    ThreadRestoreStep::factory()->failed()->create(['thread_id' => $this->thread->id]);

    $this->postJson(previewRestorePath($this->thread), [], phoneHeadersFor($this->owner))->assertStatus(202);

    Queue::assertPushed(RestoreThreadPreviewJob::class, 1);
});

it('retries a failed restore whose sandbox was already created', function () {
    $thread = $this->thread;
    $thread->update(['sandbox_state' => ThreadSandboxState::Running, 'blocked_reason' => ThreadBlockReason::RestoreFailed]);
    cachePreviewState($thread, 'starting_dev_server');
    ThreadRestoreStep::factory()->failed()->create(['thread_id' => $thread->id]);

    $this->postJson(previewRestorePath($thread), [], phoneHeadersFor($this->owner))
        ->assertStatus(202)->assertExactJson(['status' => 'restoring']);

    Queue::assertPushed(RestoreThreadPreviewJob::class, 1);
});

it('answers the same after the last step while the dev server starts', function () {
    $thread = $this->thread;
    $thread->update(['sandbox_state' => ThreadSandboxState::Running]);
    cachePreviewState($thread, 'starting_dev_server');
    ThreadRestoreStep::factory()->create(['thread_id' => $thread->id, 'status' => RestoreStepStatus::Succeeded, 'started_at' => now()->subMinute(), 'finished_at' => now()]);
    Cache::put("thread-preview:restore:{$thread->id}", true, 300);

    $this->postJson(previewRestorePath($thread), [], phoneHeadersFor($this->owner))
        ->assertStatus(202)->assertExactJson(['status' => 'restoring']);

    Queue::assertNothingPushed();
});

it('falls through to the refusal when the key check fails', function () {
    Exceptions::fake();
    $thread = $this->thread;
    $thread->update(['sandbox_state' => ThreadSandboxState::Running]);
    $this->mock(ThreadPreviewStatus::class)->shouldReceive('state')->andReturn('ready');
    Cache::partialMock()->shouldReceive('has')->with("thread-preview:restore:{$thread->id}")->andThrow(new RuntimeException('cache down'));

    $this->postJson(previewRestorePath($thread), [], phoneHeadersFor($this->owner))
        ->assertStatus(409)->assertJsonPath('error', 'nothing_to_restore');

    Exceptions::assertReported(RuntimeException::class);
    Queue::assertNothingPushed();
});

it('refuses a share recipient', function () {
    $thread = privateThreadOf($this->owner, ['sandbox_state' => ThreadSandboxState::Destroyed]);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $this->owner, ThreadShareMode::People, [$recipient->id]);

    $this->postJson(previewRestorePath($thread), [], phoneHeadersFor($recipient))
        ->assertForbidden()
        ->assertJson(['error' => 'forbidden', 'message' => 'You do not have access to this.']);

    Queue::assertNothingPushed();
    expect(ThreadRestoreStep::query()->where('thread_id', $thread->id)->count())->toBe(0);
});

it('answers a hidden thread exactly like a missing one', function () {
    $hidden = privateThreadOf($this->owner, ['sandbox_state' => ThreadSandboxState::Destroyed]);
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->postJson('/api/app/v1/threads/zzzzzzzz/preview/restore', [], $headers)->assertNotFound();
    $this->postJson(previewRestorePath($hidden), [], $headers)->assertNotFound()->assertExactJson($missing->json());
    $this->postJson("/api/app/v1/threads/{$hidden->id}/preview/restore", [], $headers)->assertNotFound()->assertExactJson($missing->json());

    Queue::assertNothingPushed();
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $path = previewRestorePath($this->thread);

    $this->postJson($path, [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $this->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson($path, [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->owner->id], isPhone: false);
    $this->postJson($path, [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');

    Queue::assertNothingPushed();
});
