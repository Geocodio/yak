<?php

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Models\SshKey;
use App\Models\Thread;
use App\Models\User;
use App\Services\Ssh\SandboxSshAddress;
use App\Services\Threads\Contracts\SandboxManager;

beforeEach(function () {
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
    config()->set('yak.ssh.internal_token', 'secret-token');
    $this->withToken('secret-token');
});

function wakeAddress(Thread $thread): array
{
    return ['address' => SandboxSshAddress::for($thread)];
}

function sandboxOperations(): array
{
    return array_column(app(SandboxManager::class)->calls, 'operation');
}

it('wakes a paused sandbox, installs keys and answers ready', function () {
    SshKey::factory()->create();
    $thread = Thread::factory()->paused()->create();

    $this->postJson('/internal/ssh/wake', wakeAddress($thread))
        ->assertOk()
        ->assertJson(['state' => 'ready', 'container' => $thread->sandbox_container]);

    expect(sandboxOperations())->toBe(['resume', 'exec'])
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

it('starts a stopped sandbox', function () {
    $thread = Thread::factory()->stopped()->create();

    $this->postJson('/internal/ssh/wake', wakeAddress($thread))->assertJson(['state' => 'ready']);

    expect(sandboxOperations())->toBe(['start', 'exec']);
});

it('answers ready for a running sandbox without waking it', function () {
    $thread = Thread::factory()->paused()->create(['sandbox_state' => ThreadSandboxState::Running]);

    $this->postJson('/internal/ssh/wake', wakeAddress($thread))->assertJson(['state' => 'ready']);

    expect(sandboxOperations())->toBe(['exec']);
});

it('answers waiting and starts nothing at the awake cap', function () {
    config(['yak.threads.awake_cap' => 1]);
    Thread::factory()->paused()->create(['sandbox_state' => ThreadSandboxState::Running]);
    $thread = Thread::factory()->stopped()->create();

    $this->postJson('/internal/ssh/wake', wakeAddress($thread))
        ->assertOk()
        ->assertJson(['state' => 'waiting', 'message' => 'Waiting for a free sandbox.']);

    expect(sandboxOperations())->toBe([])
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped);
});

it('never restores a destroyed sandbox', function () {
    $thread = Thread::factory()->destroyed()->create();

    $this->postJson('/internal/ssh/wake', wakeAddress($thread))->assertJson(['state' => 'destroyed']);

    expect(sandboxOperations())->toBe([]);
});

it('answers closed for a closed thread', function () {
    $thread = Thread::factory()->paused()->closed()->create();

    $this->postJson('/internal/ssh/wake', wakeAddress($thread))->assertJson(['state' => 'closed']);

    expect(sandboxOperations())->toBe([]);
});

it('answers no_sandbox for a thread without one, a failed restore or an unknown address', function () {
    $none = Thread::factory()->create();
    $failed = Thread::factory()->paused()->create(['blocked_reason' => ThreadBlockReason::RestoreFailed]);

    $this->postJson('/internal/ssh/wake', wakeAddress($none))->assertOk()->assertJson(['state' => 'no_sandbox']);
    $this->postJson('/internal/ssh/wake', wakeAddress($failed))->assertOk()->assertJson(['state' => 'no_sandbox']);
    $this->postJson('/internal/ssh/wake', ['address' => '127.30.1.1'])->assertOk()->assertJson(['state' => 'no_sandbox']);
    $this->postJson('/internal/ssh/wake', ['address' => '8.8.8.8'])->assertOk()->assertJson(['state' => 'no_sandbox']);

    expect(sandboxOperations())->toBe([]);
});

it('touches the thread on activity', function () {
    $thread = Thread::factory()->paused()->create(['last_activity_at' => now()->subHour()]);

    $this->postJson('/internal/ssh/activity', wakeAddress($thread))->assertOk();
    $this->postJson('/internal/ssh/activity', ['address' => '127.30.1.1'])->assertOk()->assertJson(['state' => 'no_sandbox']);

    expect($thread->fresh()->last_activity_at->isAfter(now()->subMinute()))->toBeTrue();
});

it('lists a listener per live or stopped sandbox of a yak-owned thread', function () {
    $paused = Thread::factory()->paused()->create();
    $stopped = Thread::factory()->stopped()->create();
    Thread::factory()->destroyed()->create();
    Thread::factory()->create();
    Thread::factory()->paused()->ownedByDevice()->create();

    $response = $this->getJson('/internal/ssh/listeners')->assertOk();

    expect($response->json('listeners'))->toBe([
        ['address' => SandboxSshAddress::for($paused), 'port' => 22],
        ['address' => SandboxSshAddress::for($stopped), 'port' => 22],
    ]);
});

it('returns key lines for an authorised key and none for an unknown one', function () {
    $key = SshKey::factory()->create();
    $thread = Thread::factory()->paused()->create();

    $this->postJson('/internal/ssh/authorized-keys', ['fingerprint' => $key->fingerprint])
        ->assertOk()
        ->assertJsonPath('lines.0', 'restrict,port-forwarding,permitopen="' . SandboxSshAddress::for($thread) . ':22" ' . $key->public_key);
    $this->postJson('/internal/ssh/authorized-keys', ['fingerprint' => 'SHA256:nope'])->assertOk()->assertExactJson(['lines' => []]);
    $this->postJson('/internal/ssh/authorized-keys', [])->assertUnprocessable();
});

it('returns no lines once the owner lost the right to drive', function () {
    $owner = User::factory()->create();
    $key = SshKey::factory()->forUser($owner)->create();
    $thread = Thread::factory()->paused()->create();

    $this->postJson('/internal/ssh/authorized-keys', ['fingerprint' => $key->fingerprint])->assertJsonCount(1, 'lines');

    $thread->update(['visibility' => 'private', 'owner_user_id' => User::factory()->create()->id]);

    $this->postJson('/internal/ssh/authorized-keys', ['fingerprint' => $key->fingerprint])->assertExactJson(['lines' => []]);
});

it('never puts private key material in a response', function () {
    $key = SshKey::factory()->create();
    $thread = Thread::factory()->paused()->create();

    $bodies = [
        $this->postJson('/internal/ssh/authorized-keys', ['fingerprint' => $key->fingerprint])->getContent(),
        $this->getJson('/internal/ssh/listeners')->getContent(),
        $this->postJson('/internal/ssh/wake', wakeAddress($thread))->getContent(),
    ];

    foreach ($bodies as $body) {
        expect($body)->not->toContain('PRIVATE KEY')->not->toContain('placeholder');
    }
});

it('requires the internal token on every endpoint', function () {
    $this->withToken('wrong');

    $this->getJson('/internal/ssh/listeners')->assertUnauthorized();
    $this->postJson('/internal/ssh/wake', ['address' => '127.16.0.1'])->assertUnauthorized();
    $this->postJson('/internal/ssh/activity', ['address' => '127.16.0.1'])->assertUnauthorized();
    $this->postJson('/internal/ssh/authorized-keys', ['fingerprint' => 'x'])->assertUnauthorized();
});
