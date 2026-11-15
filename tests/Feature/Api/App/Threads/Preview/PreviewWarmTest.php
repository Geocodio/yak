<?php

use App\DataTransferObjects\PreviewWakeOutcome;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Preview\ThreadPreviewWaker;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $this->owner = User::factory()->create(['name' => 'Test person']);
    $this->thread = appPreviewThread($this->owner);
});

function previewWarmPath(Thread $thread): string
{
    return appThreadPath($thread, '/preview/warm');
}

/** Runs the callbacks that Laravel defers until after the response. */
function runDeferredCallbacks(): void
{
    app(DeferredCallbackCollection::class)->invoke();
}

it('answers 204 at once and wakes after the response', function () {
    $this->mock(ThreadPreviewWaker::class)->shouldReceive('wake')->once()
        ->withArgs(fn (Thread $thread, int $hold) => $thread->is($this->thread) && $hold === 0)
        ->andReturn(PreviewWakeOutcome::waking([]));

    $this->postJson(previewWarmPath($this->thread), [], phoneHeadersFor($this->owner))->assertNoContent();
    runDeferredCallbacks();

    expect($this->thread->fresh()->preview_last_request_at->equalTo(now()))->toBeTrue();
});

it('answers 204 when the wake fails', function () {
    Exceptions::fake();
    $this->mock(ThreadPreviewWaker::class)->shouldReceive('wake')->once()->andThrow(new RuntimeException('sandbox exploded'));

    $this->postJson(previewWarmPath($this->thread), [], phoneHeadersFor($this->owner))->assertNoContent();
    runDeferredCallbacks();

    Exceptions::assertReported(RuntimeException::class);
});

it('wakes once for a web warm and an app warm within five seconds', function () {
    $this->mock(ThreadPreviewWaker::class)->shouldReceive('wake')->twice()->andReturn(PreviewWakeOutcome::waking([]));

    $this->actingAs($this->owner)->postJson(route('threads.preview.warm', $this->thread))->assertOk();
    $this->postJson(previewWarmPath($this->thread), [], phoneHeadersFor($this->owner))->assertNoContent();
    runDeferredCallbacks();

    $this->travel(6)->seconds();
    $this->postJson(previewWarmPath($this->thread), [], phoneHeadersFor($this->owner))->assertNoContent();
    runDeferredCallbacks();
});

it('wakes nothing for a closed, local machine or destroyed thread', function (Closure $arrange) {
    Queue::fake();
    $thread = $arrange($this->owner);

    $this->postJson(previewWarmPath($thread), [], phoneHeadersFor($this->owner))->assertNoContent();
    runDeferredCallbacks();

    expect(app(SandboxManager::class)->calls)->toBe([]);
    Queue::assertNothingPushed();
})->with([
    'closed' => [fn (User $owner) => tap(appPreviewThread($owner, ['preview_hostname' => null]), fn (Thread $thread) => $thread->forceFill(['status' => Thread::factory()->closed()->make()->status])->save())],
    'on a computer' => [fn (User $owner) => Thread::factory()->ownedByDevice()->create(['owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id])],
    'destroyed' => [fn (User $owner) => appPreviewThread($owner, ['preview_hostname' => null, 'sandbox_state' => ThreadSandboxState::Destroyed, 'sandbox_container' => null])],
]);

it('answers 204 when the cache is down', function () {
    Exceptions::fake();
    Cache::partialMock()->shouldReceive('add')->with("thread-preview:warm:{$this->thread->id}", true, 5)->andThrow(new RuntimeException('cache down'));
    $this->mock(ThreadPreviewWaker::class)->shouldNotReceive('wake');

    $this->postJson(previewWarmPath($this->thread), [], phoneHeadersFor($this->owner))->assertNoContent();
    runDeferredCallbacks();

    Exceptions::assertReported(RuntimeException::class);
});

it('lets a share recipient warm', function () {
    $thread = privateThreadOf($this->owner, ['sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => 'yak-thread-1']);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $this->owner, ThreadShareMode::People, [$recipient->id]);
    $this->mock(ThreadPreviewWaker::class)->shouldReceive('wake')->once()->andReturn(PreviewWakeOutcome::waking([]));

    $this->postJson(previewWarmPath($thread), [], phoneHeadersFor($recipient))->assertNoContent();
    runDeferredCallbacks();
});

it('answers a hidden thread exactly like a missing one', function () {
    $hidden = privateThreadOf($this->owner, ['sandbox_state' => ThreadSandboxState::Running]);
    $headers = phoneHeadersFor(User::factory()->create());
    $this->mock(ThreadPreviewWaker::class)->shouldNotReceive('wake');

    $missing = $this->postJson('/api/app/v1/threads/zzzzzzzz/preview/warm', [], $headers)->assertNotFound();
    $this->postJson(previewWarmPath($hidden), [], $headers)->assertNotFound()->assertExactJson($missing->json());
    $this->postJson("/api/app/v1/threads/{$hidden->id}/preview/warm", [], $headers)->assertNotFound()->assertExactJson($missing->json());
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $path = previewWarmPath($this->thread);

    $this->postJson($path, [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $this->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson($path, [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->owner->id], isPhone: false);
    $this->postJson($path, [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
