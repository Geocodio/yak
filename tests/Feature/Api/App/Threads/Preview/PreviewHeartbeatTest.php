<?php

use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $this->owner = User::factory()->create(['name' => 'Test person']);
    $this->thread = appPreviewThread($this->owner);
});

function previewHeartbeatPath(Thread $thread): string
{
    return appThreadPath($thread, '/preview/heartbeat');
}

it('records preview activity and answers 204', function () {
    $headers = phoneHeadersFor($this->owner);

    $this->postJson(previewHeartbeatPath($this->thread), [], $headers)->assertNoContent();

    $thread = $this->thread->fresh();
    expect($thread->preview_last_request_at->equalTo(now()))->toBeTrue()
        ->and($thread->last_activity_at->equalTo(now()))->toBeTrue();

    $this->travel(10)->seconds();
    $this->postJson(previewHeartbeatPath($this->thread), [], $headers)->assertNoContent();

    expect($this->thread->fresh()->preview_last_request_at->equalTo(CarbonImmutable::parse('2026-11-15 12:00:00')))->toBeTrue();
});

it('answers 204 for a thread that never had a sandbox', function () {
    $thread = appPreviewThread($this->owner, ['preview_hostname' => null, 'sandbox_state' => ThreadSandboxState::None, 'sandbox_container' => null]);

    $this->postJson(previewHeartbeatPath($thread), [], phoneHeadersFor($this->owner))->assertNoContent();
});

it('answers 204 when the cache is down', function () {
    Exceptions::fake();
    Cache::partialMock()->shouldReceive('add')->with("thread-preview:activity:{$this->thread->id}", true, 30)->andThrow(new RuntimeException('cache down'));

    $this->postJson(previewHeartbeatPath($this->thread), [], phoneHeadersFor($this->owner))->assertNoContent();

    Exceptions::assertReported(RuntimeException::class);
});

it('lets a share recipient send it', function () {
    $thread = privateThreadOf($this->owner, ['sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => 'yak-thread-1']);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $this->owner, ThreadShareMode::People, [$recipient->id]);

    $this->postJson(previewHeartbeatPath($thread), [], phoneHeadersFor($recipient))->assertNoContent();
});

it('answers a hidden thread exactly like a missing one', function () {
    $hidden = privateThreadOf($this->owner, ['sandbox_state' => ThreadSandboxState::Running]);
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->postJson('/api/app/v1/threads/zzzzzzzz/preview/heartbeat', [], $headers)->assertNotFound();
    $this->postJson(previewHeartbeatPath($hidden), [], $headers)->assertNotFound()->assertExactJson($missing->json());
    $this->postJson("/api/app/v1/threads/{$hidden->id}/preview/heartbeat", [], $headers)->assertNotFound()->assertExactJson($missing->json());
    expect($hidden->fresh()->preview_last_request_at)->toBeNull();
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $path = previewHeartbeatPath($this->thread);

    $this->postJson($path, [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $this->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->postJson($path, [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->owner->id], isPhone: false);
    $this->postJson($path, [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
