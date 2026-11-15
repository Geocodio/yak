<?php

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Enums\TurnState;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $this->owner = User::factory()->create(['name' => 'Test person']);
    $this->thread = appPreviewThread($this->owner);
});

function previewStatusPath(Thread $thread): string
{
    return appThreadPath($thread, '/preview/status');
}

it('reports a ready preview with its address', function () {
    cachePreviewState($this->thread, 'ready');

    $response = $this->getJson(previewStatusPath($this->thread), phoneHeadersFor($this->owner))->assertOk()
        ->assertExactJson([
            'state' => 'ready', 'steps' => [], 'log' => [], 'error' => null,
            'url' => 'https://t-k3m9p2qa7x.yak.example.com/',
            'port' => 3000, 'coldStartCommand' => 'npm run dev',
            'setup' => ['intent' => null, 'isRunning' => false, 'endedWithoutManifest' => false],
        ]);
    ContractFixture::assertMatches('app', 'thread-preview-status', $response->json());
});

it('reports a starting dev server with its steps and log', function () {
    cachePreviewState($this->thread, 'starting', ['> example-web@1.0.0 dev', '> vite --port 3000']);

    $response = $this->getJson(previewStatusPath($this->thread), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('state', 'starting_dev_server')
        ->assertJsonPath('steps', ['Waking the sandbox', 'Starting the dev server'])
        ->assertJsonPath('log', ['> example-web@1.0.0 dev', '> vite --port 3000'])
        ->assertJsonPath('url', null)
        ->assertJsonPath('error', null);
    ContractFixture::assertMatches('app', 'thread-preview-status-starting', $response->json());
});

it('reports a failing dev server while Yak fixes it', function () {
    cachePreviewState($this->thread, 'failing', ['> example-web@1.0.0 dev', 'Error: Cannot find module \'vite\''], 'The dev server exited before it answered on port 3000.');
    runningPreviewSetup($this->thread, 'fix_preview');

    $response = $this->getJson(previewStatusPath($this->thread), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('state', 'failing')
        ->assertJsonPath('error', 'The dev server exited before it answered on port 3000.')
        ->assertJsonPath('log', ['> example-web@1.0.0 dev', 'Error: Cannot find module \'vite\''])
        ->assertJsonPath('url', null)
        ->assertJsonPath('setup', ['intent' => 'fix_preview', 'isRunning' => true, 'endedWithoutManifest' => false]);
    ContractFixture::assertMatches('app', 'thread-preview-status-failing', $response->json());
});

it('reports each other state without running anything', function (Closure $arrange, string $state, ?string $error) {
    Queue::fake();
    $thread = $arrange($this->owner);

    $this->getJson(previewStatusPath($thread), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('state', $state)
        ->assertJsonPath('url', null)
        ->assertJsonPath('error', $error)
        ->assertJsonPath('log', []);

    expect(app(SandboxManager::class)->calls)->toBe([]);
    Queue::assertNothingPushed();
})->with([
    'paused' => [fn (User $owner) => appPreviewThread($owner, ['preview_hostname' => null, 'sandbox_state' => ThreadSandboxState::Paused]), 'waking_sandbox', null],
    'waiting for a slot' => [fn (User $owner) => appPreviewThread($owner, ['preview_hostname' => null, 'sandbox_state' => ThreadSandboxState::Paused, 'blocked_reason' => ThreadBlockReason::WaitingForSandbox]), 'waiting_for_slot', null],
    'destroyed' => [fn (User $owner) => appPreviewThread($owner, ['preview_hostname' => null, 'sandbox_state' => ThreadSandboxState::Destroyed, 'sandbox_container' => null]), 'destroyed', null],
    'never had a sandbox' => [fn (User $owner) => appPreviewThread($owner, ['preview_hostname' => null, 'sandbox_state' => ThreadSandboxState::None, 'sandbox_container' => null]), 'destroyed', null],
    'closed' => [fn (User $owner) => tap(appPreviewThread($owner, ['preview_hostname' => null]), fn (Thread $thread) => $thread->forceFill(['status' => Thread::factory()->closed()->make()->status])->save()), 'closed', null],
    'on a computer' => [fn (User $owner) => Thread::factory()->ownedByDevice()->create(['owner_user_id' => $owner->id, 'created_by_user_id' => $owner->id]), 'local_machine', null],
    'no manifest' => [fn (User $owner) => tap(appPreviewThread($owner, ['preview_hostname' => null]), fn (Thread $thread) => cachePreviewState($thread, 'no_manifest', manifest: null)), 'no_manifest', null],
    'invalid manifest' => [fn (User $owner) => tap(appPreviewThread($owner, ['preview_hostname' => null]), fn (Thread $thread) => cachePreviewState($thread, 'invalid_manifest', error: 'The preview file has no port.')), 'invalid_manifest', 'The preview file has no port.'],
]);

it('reports a running sandbox with an empty cache as starting', function () {
    Queue::fake();

    $this->getJson(previewStatusPath($this->thread), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('state', 'starting_dev_server')
        ->assertJsonPath('port', null)
        ->assertJsonPath('coldStartCommand', null);

    expect(app(SandboxManager::class)->calls)->toBe([]);
    Queue::assertNothingPushed();
});

it('says the add request ended without a manifest', function () {
    cachePreviewState($this->thread, 'no_manifest', manifest: null);
    runningPreviewSetup($this->thread, 'add_preview_manifest', TurnState::Completed);

    $this->getJson(previewStatusPath($this->thread), phoneHeadersFor($this->owner))->assertOk()
        ->assertJsonPath('setup', ['intent' => 'add_preview_manifest', 'isRunning' => false, 'endedWithoutManifest' => true]);
});

it('matches the panel prop of thread show', function () {
    cachePreviewState($this->thread, 'failing', ['Error: Cannot find module \'vite\''], 'The dev server exited before it answered on port 3000.');
    runningPreviewSetup($this->thread, 'fix_preview');
    $headers = phoneHeadersFor($this->owner);

    $status = $this->getJson(previewStatusPath($this->thread), $headers)->assertOk()->json();
    $preview = $this->getJson(appThreadPath($this->thread), $headers)->assertOk()->json('thread.preview');

    expect($status['port'])->toBe($preview['port'])
        ->and($status['coldStartCommand'])->toBe($preview['coldStartCommand'])
        ->and($status['setup'])->toBe($preview['setup']);
});

it('assigns no preview host while the preview is not ready', function () {
    $thread = appPreviewThread($this->owner, ['preview_hostname' => null]);
    cachePreviewState($thread, 'starting');

    $this->getJson(previewStatusPath($thread), phoneHeadersFor($this->owner))->assertOk()->assertJsonPath('url', null);

    expect($thread->fresh()->preview_hostname)->toBeNull();
});

it('lets a share recipient read the status', function () {
    $thread = privateThreadOf($this->owner, ['sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => 'yak-thread-1']);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $this->owner, ThreadShareMode::People, [$recipient->id]);

    $this->getJson(previewStatusPath($thread), phoneHeadersFor($recipient))->assertOk();
});

it('answers a hidden thread exactly like a missing one', function () {
    $hidden = privateThreadOf($this->owner, ['sandbox_state' => ThreadSandboxState::Running]);
    $headers = phoneHeadersFor(User::factory()->create());

    $missing = $this->getJson('/api/app/v1/threads/zzzzzzzz/preview/status', $headers)->assertNotFound();
    $this->getJson(previewStatusPath($hidden), $headers)->assertNotFound()->assertExactJson($missing->json());
    $this->getJson("/api/app/v1/threads/{$hidden->id}/preview/status", $headers)->assertNotFound()->assertExactJson($missing->json());
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $path = previewStatusPath($this->thread);

    $this->getJson($path, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders(['user_id' => $this->owner->id]);
    $device->update(['revoked_at' => now()]);
    $this->getJson($path, $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $this->owner->id], isPhone: false);
    $this->getJson($path, $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
});
