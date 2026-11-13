<?php

use App\DataTransferObjects\PreviewWakeOutcome;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Jobs\Threads\RestoreThreadPreviewJob;
use App\Models\ThreadShare;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Preview\ThreadPreviewDevServer;
use App\Services\Threads\Preview\ThreadPreviewWaker;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    config(['yak.deployments.hostname_suffix' => 'yak.example.com']);
    $this->world = VisibilityWorld::build();
    $this->thread = $this->world->thread;
    $this->thread->update(['sandbox_container' => 'yak-thread-7', 'sandbox_state' => ThreadSandboxState::Running]);
});

dataset('strangers', ['other', 'admin']);

it('answers 404 to a stranger on every preview endpoint of a private thread', function (string $stranger) {
    $user = $this->world->{$stranger};
    $base = "/threads/{$this->thread->public_id}/preview";

    $this->actingAs($user)->getJson("{$base}/status")->assertNotFound();
    $this->actingAs($user)->postJson("{$base}/heartbeat")->assertNotFound();
    $this->actingAs($user)->postJson("{$base}/restore")->assertNotFound();
    $this->actingAs($user)->postJson("{$base}/warm")->assertNotFound();
})->with('strangers');

it('reports a ready dev server with the preview url', function () {
    Cache::put("thread-preview:state:{$this->thread->id}", 'ready');

    $this->actingAs($this->world->owner)->getJson("/threads/{$this->thread->public_id}/preview/status")
        ->assertOk()
        ->assertJsonPath('state', 'ready')
        ->assertJsonPath('url', fn ($url) => str_starts_with($url, 'https://t-'));
});

it('reports each state without running anything', function (array $setup, string $state) {
    $this->thread->update($setup[0]);
    if (isset($setup[1])) {
        Cache::put("thread-preview:state:{$this->thread->id}", $setup[1]);
    }

    $this->actingAs($this->world->owner)->getJson("/threads/{$this->thread->public_id}/preview/status")
        ->assertOk()->assertJsonPath('state', $state)->assertJsonPath('url', null);

    expect(app(SandboxManager::class)->calls)->toBe([]);
})->with([
    'paused' => [[['sandbox_state' => ThreadSandboxState::Paused]], 'waking_sandbox'],
    'destroyed' => [[['sandbox_state' => ThreadSandboxState::Destroyed]], 'destroyed'],
    'closed' => [[['status' => ThreadStatus::Closed]], 'closed'],
    'starting' => [[[], 'starting'], 'starting_dev_server'],
    'failing' => [[[], 'failing'], 'failing'],
    'no manifest' => [[[], 'no_manifest'], 'no_manifest'],
    'invalid manifest' => [[[], 'invalid_manifest'], 'invalid_manifest'],
]);

it('refreshes the activity window on a heartbeat', function () {
    $this->travelTo(now()->startOfSecond());
    $this->thread->update(['last_activity_at' => now()->subHour()]);

    $this->actingAs($this->world->owner)->postJson("/threads/{$this->thread->public_id}/preview/heartbeat")->assertOk();

    $fresh = $this->thread->fresh();
    expect($fresh->last_activity_at->isSameSecond(now()))->toBeTrue()
        ->and($fresh->preview_last_request_at->isSameSecond(now()))->toBeTrue();
});

it('lets a viewer who cannot drive see that restore is forbidden', function () {
    ThreadShare::factory()->create(['thread_id' => $this->thread->id, 'user_id' => $this->world->other->id]);

    $this->actingAs($this->world->other)->postJson("/threads/{$this->thread->public_id}/preview/restore")->assertForbidden();
});

it('refuses to restore a closed thread', function () {
    Queue::fake();
    $this->thread->update(['status' => ThreadStatus::Closed]);

    $this->actingAs($this->world->owner)->postJson("/threads/{$this->thread->public_id}/preview/restore")->assertStatus(422);
    Queue::assertNothingPushed();
});

it('dispatches one restore job for two clicks', function () {
    Queue::fake();
    $this->thread->update(['sandbox_state' => ThreadSandboxState::Destroyed]);

    $this->actingAs($this->world->owner)->postJson("/threads/{$this->thread->public_id}/preview/restore")->assertStatus(202);
    $this->actingAs($this->world->owner)->postJson("/threads/{$this->thread->public_id}/preview/restore")->assertStatus(202);

    Queue::assertPushed(RestoreThreadPreviewJob::class, 1);
});

it('starts the dev server after a restore', function () {
    $devServer = $this->mock(ThreadPreviewDevServer::class);
    $devServer->shouldReceive('ensureStarted')->once()->andReturn('starting');
    $sandbox = $this->mock(ThreadSandbox::class);
    $sandbox->shouldReceive('restore')->once()->andReturn(true);

    (new RestoreThreadPreviewJob($this->thread))->handle($sandbox, $devServer);
});

it('wakes the preview for the owner and throttles repeat requests', function () {
    $this->mock(ThreadPreviewWaker::class)->shouldReceive('wake')->once()->withArgs(fn ($thread, $hold) => $thread->is($this->thread) && $hold === 0)->andReturn(PreviewWakeOutcome::waking());

    $this->actingAs($this->world->owner)->postJson("/threads/{$this->thread->public_id}/preview/warm")->assertOk()->assertJsonPath('state', 'waking');
    $this->actingAs($this->world->owner)->postJson("/threads/{$this->thread->public_id}/preview/warm")->assertOk();
});

it('starts no wake for a stranger', function (string $stranger) {
    $this->mock(ThreadPreviewWaker::class)->shouldNotReceive('wake');

    $this->actingAs($this->world->{$stranger})->postJson("/threads/{$this->thread->public_id}/preview/warm")->assertNotFound();
})->with('strangers');
