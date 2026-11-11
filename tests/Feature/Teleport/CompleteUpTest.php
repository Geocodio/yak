<?php

use App\DataTransferObjects\ResolvedAccount;
use App\DataTransferObjects\SandboxWake;
use App\Enums\TeleportState;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\Teleport\BranchHeadVerifier;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

const COMPLETE_SESSION = 'd3d3d3d3-0000-4000-8000-000000000004';
const COMPLETE_HEAD = 'abc123def456';

beforeEach(function () {
    $this->storePath = sys_get_temp_dir() . '/' . Str::uuid();
    config()->set('yak.threads.session_store_path', $this->storePath);
    config()->set('yak.threads.session_store_key', base64_encode(sodium_crypto_secretbox_keygen()));
    Repository::factory()->create(['slug' => 'acme/widgets', 'github_full_name' => 'acme/widgets']);
    [$this->device, $this->token] = makeDevice();

    $this->heads = Mockery::mock(BranchHeadVerifier::class);
    $this->heads->shouldReceive('headOf')->andReturn(COMPLETE_HEAD)->byDefault();
    app()->instance(BranchHeadVerifier::class, $this->heads);

    $this->account = ClaudeAccount::factory()->system()->create();
    $this->resolver = Mockery::mock(ClaudeAccountResolver::class);
    $this->resolver->shouldReceive('forNewThread')->andReturn(new ResolvedAccount($this->account, 'sonnet'))->byDefault();
    app()->instance(ClaudeAccountResolver::class, $this->resolver);

    $this->sandboxes = new FakeSandboxManager;
    app()->instance(SandboxManager::class, $this->sandboxes);

    $this->sessions = Mockery::mock(SessionFileSync::class);
    $this->sessions->shouldReceive('restoreIntoSandbox')->byDefault();
    app()->instance(SessionFileSync::class, $this->sessions);

    $this->sandbox = Mockery::mock(ThreadSandbox::class);
    $this->sandbox->shouldReceive('ensureAwake')->andReturn(SandboxWake::ready())->byDefault();
    app()->instance(ThreadSandbox::class, $this->sandbox);

    $this->turns = Mockery::mock(ThreadTurns::class);
    app()->instance(ThreadTurns::class, $this->turns);
});

afterEach(function () {
    File::deleteDirectory($this->storePath);
});

/**
 * Begins an up teleport and uploads a checkpointed session; returns the begin response body.
 *
 * @return array<string, mixed>
 */
function beginAndUpload(object $test, bool $checkpoint = true): array
{
    $begin = $test->postJson('/api/cli/v1/teleports', [
        'direction' => 'up',
        'session_id' => COMPLETE_SESSION,
        'title_hint' => 'Fix the cache',
        'local_path' => '/Users/ada/widgets',
        'repos' => [['repo' => 'acme/widgets', 'role' => 'primary', 'branch' => 'main', 'on_default_branch' => true, 'head_sha' => COMPLETE_HEAD]],
    ], cliHeaders($test->token))->assertOk()->json();

    $base = "/api/cli/v1/threads/{$begin['thread']['id']}/session";
    $chunk = "{\"type\":\"user\"}\n";
    $rel = COMPLETE_SESSION . '.jsonl';
    $test->postJson("{$base}/append", [
        'session_id' => COMPLETE_SESSION, 'rel_path' => $rel, 'offset' => 0,
        'chunk' => base64_encode($chunk), 'chunk_sha256' => hash('sha256', $chunk), 'tail_sha256' => hash('sha256', ''),
    ], cliHeaders($test->token))->assertOk();

    if ($checkpoint) {
        $test->postJson("{$base}/checkpoint", ['session_id' => COMPLETE_SESSION, 'rel_path' => $rel, 'sha256' => hash('sha256', $chunk)], cliHeaders($test->token))->assertOk();
    }

    return $begin;
}

/**
 * @return array<string, mixed>
 */
function completeBody(string $branch = 'yak/fix-the-cache', ?string $message = null): array
{
    return array_filter([
        'repos' => [['repo' => 'acme/widgets', 'branch' => $branch, 'head_sha' => COMPLETE_HEAD]],
        'message' => $message,
    ]);
}

it('completes up and moves ownership last', function () {
    $begin = beginAndUpload($this);
    $thread = Thread::withoutGlobalScopes()->findOrFail($begin['thread']['id']);
    $ownerDuringPreparation = null;
    $this->sandbox->shouldReceive('ensureAwake')->once()->andReturnUsing(function (Thread $thread) use (&$ownerDuringPreparation) {
        $ownerDuringPreparation = Thread::withoutGlobalScopes()->find($thread->id)->owner_side;
        $thread->forceFill(['sandbox_state' => ThreadSandboxState::Running])->save();

        return SandboxWake::ready();
    });
    $this->turns->shouldNotReceive('start');

    $response = $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(), cliHeaders($this->token))
        ->assertOk()
        ->assertJsonPath('teleport.state', 'completed')
        ->assertJsonPath('ownership', 'yak')
        ->assertJsonPath('sandbox.state', 'ready')
        ->assertJsonMissingPath('sandbox.repos')
        ->assertJsonPath('turn_started', false)
        ->assertJsonPath('held_events_delivered', 0)
        ->assertJsonPath('marker.thread_id', $thread->id)
        ->assertJsonPath('marker.owner', 'yak');

    $thread->refresh();
    expect($ownerDuringPreparation)->toBe(ThreadOwnerSide::Device)
        ->and($thread->owner_side)->toBe(ThreadOwnerSide::Yak)
        ->and($thread->owner_device_id)->toBeNull()
        ->and($thread->first_teleported_at)->not->toBeNull()
        ->and($thread->owner_changed_at)->not->toBeNull()
        ->and($thread->branch)->toBe('yak/fix-the-cache')
        ->and($thread->repositories()->first()->branch)->toBe('yak/fix-the-cache')
        ->and($this->device->refresh()->last_teleport_thread_id)->toBe($thread->id);
    expect($response->json('marker.thread_url'))->toBe(route('threads.show', $thread));
});

it('answers push_not_visible and leaves the owner', function () {
    $begin = beginAndUpload($this);
    $this->heads->shouldReceive('headOf')->andReturn('other-sha');

    $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(), cliHeaders($this->token))
        ->assertStatus(409)->assertJsonPath('error', 'push_not_visible');

    expect(Thread::withoutGlobalScopes()->find($begin['thread']['id'])->owner_side)->toBe(ThreadOwnerSide::Device)
        ->and(Teleport::query()->find($begin['teleport_id'])->state)->toBe(TeleportState::Open);
});

it('rejects a primary repo without head_sha and leaves the owner', function () {
    $begin = beginAndUpload($this);
    $this->heads->shouldNotReceive('headOf');
    $body = completeBody();
    $body['repos'][0]['head_sha'] = null;

    $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", $body, cliHeaders($this->token))
        ->assertStatus(409)->assertJsonPath('error', 'push_not_visible');

    expect(Thread::withoutGlobalScopes()->find($begin['thread']['id'])->owner_side)->toBe(ThreadOwnerSide::Device)
        ->and(Teleport::query()->find($begin['teleport_id'])->state)->toBe(TeleportState::Open);
});

it('answers session_incomplete without a matching checkpoint', function () {
    $begin = beginAndUpload($this, checkpoint: false);

    $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(), cliHeaders($this->token))
        ->assertStatus(409)->assertJsonPath('error', 'session_incomplete');

    expect(Thread::withoutGlobalScopes()->find($begin['thread']['id'])->owner_side)->toBe(ThreadOwnerSide::Device);
});

it('marks the teleport failed and leaves the owner when preparation fails', function () {
    $begin = beginAndUpload($this);
    $this->sandbox->shouldReceive('ensureAwake')->andReturn(new SandboxWake(false, false, false));

    $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(), cliHeaders($this->token))
        ->assertStatus(409)->assertJsonPath('error', 'teleport_failed')->assertJsonPath('details.code', 'teleport_failed');

    $thread = Thread::withoutGlobalScopes()->find($begin['thread']['id']);
    expect($thread->owner_side)->toBe(ThreadOwnerSide::Device)
        ->and($thread->owner_device_id)->toBe($this->device->id)
        ->and(Teleport::query()->find($begin['teleport_id'])->state)->toBe(TeleportState::Failed);
});

it('completes with a deferred sandbox when no slot is free', function () {
    $begin = beginAndUpload($this);
    $this->sandbox->shouldReceive('ensureAwake')->andReturn(SandboxWake::waitingForSlot());

    $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(), cliHeaders($this->token))
        ->assertOk()
        ->assertJsonPath('ownership', 'yak')
        ->assertJsonPath('sandbox.state', 'waiting_for_slot');

    expect(Thread::withoutGlobalScopes()->find($begin['thread']['id'])->owner_side)->toBe(ThreadOwnerSide::Yak)
        ->and($this->sandboxes->calls)->toBe([]);
});

it('starts a turn with the message', function () {
    $begin = beginAndUpload($this);
    $this->turns->shouldReceive('start')->once()->withArgs(function ($task, string $prompt, $driver, $model) {
        return $task->source === 'teleport' && $prompt === 'continue with the cache tests' && $driver->id === $this->device->user_id && $model === null;
    });

    $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(message: 'continue with the cache tests'), cliHeaders($this->token))
        ->assertOk()->assertJsonPath('turn_started', true);
});

it('keeps ownership on Yak when the first turn cannot start', function () {
    $begin = beginAndUpload($this);
    $this->turns->shouldReceive('start')->andThrow(new RuntimeException('busy'));

    $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(message: 'go'), cliHeaders($this->token))
        ->assertOk()->assertJsonPath('turn_started', false)->assertJsonPath('ownership', 'yak');
});

it('returns the same body when complete is repeated', function () {
    $begin = beginAndUpload($this);
    $this->turns->shouldReceive('start')->once();

    $first = $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(message: 'go'), cliHeaders($this->token))->assertOk();
    $second = $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(message: 'go'), cliHeaders($this->token))->assertOk();

    expect($second->json())->toBe($first->json());
});

it('answers 202 while the teleport is preparing and teleport_failed after a failure', function () {
    $begin = beginAndUpload($this);
    $teleport = Teleport::query()->find($begin['teleport_id']);
    $teleport->update(['state' => TeleportState::Preparing]);

    $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(), cliHeaders($this->token))
        ->assertStatus(202)->assertJsonPath('teleport.state', 'preparing');

    $teleport->update(['state' => TeleportState::Failed, 'failure_code' => 'teleport_failed', 'failure_message' => 'boom']);
    $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(), cliHeaders($this->token))
        ->assertStatus(409)->assertJsonPath('error', 'teleport_failed');
});

it('fixes the account at the first up and records progress', function () {
    $begin = beginAndUpload($this);

    $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(), cliHeaders($this->token))->assertOk();

    $thread = Thread::withoutGlobalScopes()->find($begin['thread']['id']);
    expect($thread->claude_account_id)->toBe($this->account->id)
        ->and($thread->model)->toBe('sonnet');
    $steps = collect(Teleport::query()->find($begin['teleport_id'])->progress)->pluck('status', 'step')->all();
    expect($steps)->toMatchArray(['restore' => 'done', 'fetch_branch' => 'done', 'setup' => 'done', 'refresh' => 'done', 'session_restore' => 'done']);
});

it('resets a live sandbox to the pushed head on a second up and runs the symlink', function () {
    $first = beginAndUpload($this);
    $this->postJson("/api/cli/v1/teleports/{$first['teleport_id']}/complete", completeBody(), cliHeaders($this->token))->assertOk();
    $thread = Thread::withoutGlobalScopes()->find($first['thread']['id']);

    // The thread comes back to the computer and goes up again with its sandbox running.
    $thread->forceFill(['owner_side' => ThreadOwnerSide::Device, 'owner_device_id' => $this->device->id, 'sandbox_state' => ThreadSandboxState::Running])->save();
    $second = $this->postJson('/api/cli/v1/teleports', [
        'direction' => 'up', 'session_id' => COMPLETE_SESSION,
        'repos' => [['repo' => 'acme/widgets', 'role' => 'primary', 'branch' => 'yak/fix-the-cache', 'on_default_branch' => false, 'head_sha' => COMPLETE_HEAD]],
    ], cliHeaders($this->token))->assertOk()->json();
    $chunkSha = hash('sha256', "{\"type\":\"user\"}\n");
    $this->postJson("/api/cli/v1/threads/{$thread->id}/session/checkpoint", ['session_id' => COMPLETE_SESSION, 'rel_path' => COMPLETE_SESSION . '.jsonl', 'sha256' => $chunkSha], cliHeaders($this->token))->assertOk();

    $this->sandboxes->calls = [];
    $this->sessions->shouldReceive('restoreIntoSandbox')->once();
    $this->sandbox->shouldReceive('ensureAwake')->andReturn(SandboxWake::ready());

    $this->postJson("/api/cli/v1/teleports/{$second['teleport_id']}/complete", completeBody(), cliHeaders($this->token))->assertOk();

    $commands = collect($this->sandboxes->calls)->where('operation', 'exec')->map(fn (array $call): string => implode(' ', $call['arguments']['command']))->all();
    expect($commands)->toContain('git -C /workspace fetch origin yak/fix-the-cache')
        ->toContain('git -C /workspace reset --hard ' . COMPLETE_HEAD);
    expect(collect($commands)->contains(fn (string $command): bool => str_contains($command, 'ln -sfn')))->toBeTrue();
});

it('lets only the owning device complete', function () {
    $begin = beginAndUpload($this);
    [, $otherToken] = makeDevice($this->device->user);

    $this->postJson("/api/cli/v1/teleports/{$begin['teleport_id']}/complete", completeBody(), cliHeaders($otherToken))
        ->assertNotFound()->assertJsonPath('error', 'teleport_not_found');
});
