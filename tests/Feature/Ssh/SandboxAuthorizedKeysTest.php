<?php

use App\Enums\ThreadSandboxState;
use App\Models\SshKey;
use App\Models\Thread;
use App\Models\User;
use App\Services\Ssh\SandboxAuthorizedKeys;
use App\Services\Ssh\SshPublicKey;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Support\Facades\Process;

function sshKeysRunningThread(array $state = []): Thread
{
    return Thread::factory()->paused()->create(['sandbox_state' => ThreadSandboxState::Running, ...$state]);
}

function ephemeralKey(): SshPublicKey
{
    return SshPublicKey::parse('ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . random_bytes(32)));
}

function writtenContent(Thread $thread): string
{
    $calls = array_values(array_filter(
        app(SandboxManager::class)->calls,
        fn (array $call): bool => $call['operation'] === 'exec' && $call['threadId'] === $thread->id,
    ));

    return (string) end($calls)['arguments']['stdin'];
}

it('writes only the keys of people who may drive the thread', function () {
    $owner = User::factory()->create();
    $ownerKey = SshKey::factory()->forUser($owner)->create();
    $strangerKey = SshKey::factory()->create();
    $thread = sshKeysRunningThread(['visibility' => 'private', 'owner_user_id' => $owner->id]);

    app(SandboxAuthorizedKeys::class)->sync($thread);

    $content = writtenContent($thread);
    expect($content)->toContain("restrict,pty {$ownerKey->public_key}\n")
        ->and($content)->not->toContain($strangerKey->public_key);
});

it('writes every keyed user for a team thread', function () {
    $first = SshKey::factory()->create();
    $second = SshKey::factory()->create();
    $thread = sshKeysRunningThread();

    app(SandboxAuthorizedKeys::class)->sync($thread);

    expect(writtenContent($thread))->toContain($first->public_key)->toContain($second->public_key);
});

it('installs the file with private modes through sh', function () {
    $thread = sshKeysRunningThread();

    app(SandboxAuthorizedKeys::class)->sync($thread);

    $call = app(SandboxManager::class)->calls[0];
    expect($call['arguments']['command'])->toBe(['sh', '-c', 'umask 077 && mkdir -p ~/.ssh && cat > ~/.ssh/authorized_keys.new && mv ~/.ssh/authorized_keys.new ~/.ssh/authorized_keys']);
});

it('adds an ephemeral key until its ttl passes', function () {
    $thread = sshKeysRunningThread();
    $key = ephemeralKey();

    app(SandboxAuthorizedKeys::class)->addEphemeral($thread, $key, 900);
    expect(writtenContent($thread))->toContain("restrict,pty {$key->line()}");

    $this->travel(901)->seconds();
    app(SandboxAuthorizedKeys::class)->sync($thread);
    expect(writtenContent($thread))->not->toContain($key->line());
});

it('removes an ephemeral key', function () {
    $thread = sshKeysRunningThread();
    $key = ephemeralKey();
    $keys = app(SandboxAuthorizedKeys::class);

    $keys->addEphemeral($thread, $key, 900);
    $keys->removeEphemeral($thread, $key->fingerprint);

    expect(writtenContent($thread))->not->toContain($key->line());
});

it('sweeps expired ephemeral keys and re-syncs only running sandboxes', function () {
    $running = sshKeysRunningThread();
    $paused = Thread::factory()->paused()->create();
    $keys = app(SandboxAuthorizedKeys::class);
    $keys->addEphemeral($running, ephemeralKey(), 60);
    $keys->addEphemeral($paused, ephemeralKey(), 60);
    app(SandboxManager::class)->calls = [];

    $this->travel(120)->seconds();
    $keys->sweep();

    $execs = array_column(app(SandboxManager::class)->calls, 'threadId');
    expect($execs)->toBe([$running->id]);

    app(SandboxManager::class)->calls = [];
    $keys->sweep();
    expect(app(SandboxManager::class)->calls)->toBe([]);
});

it('does not swallow a failed write', function () {
    $thread = sshKeysRunningThread();
    app(SandboxManager::class)->queueExecResult(Process::result(errorOutput: 'disk full', exitCode: 1));

    app(SandboxAuthorizedKeys::class)->sync($thread);
})->throws(RuntimeException::class, 'disk full');
