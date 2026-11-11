<?php

use App\Enums\ThreadSandboxState;
use App\Models\SshKey;
use App\Models\Thread;
use App\Models\User;
use App\Services\Ssh\SandboxAuthorizedKeys;
use App\Services\Ssh\SandboxSshAddress;
use App\Services\Threads\Contracts\SandboxManager;

function journeyKeyLine(string $comment = ''): string
{
    return trim('ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . random_bytes(32)) . ' ' . $comment);
}

function journeyLastInstalledKeys(): string
{
    return (string) collect(app(SandboxManager::class)->calls)->where('operation', 'exec')->last()['arguments']['stdin'];
}

it('walks shell access from key upload to revocation', function () {
    config()->set('inertia.testing.ensure_pages_exist', false);
    config()->set('app.url', 'https://yak.example.test');
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
    config()->set('yak.ssh.internal_token', 'secret-token');
    config()->set('yak.ssh.jump_host', 'ssh.example.test');
    config()->set('yak.ssh.terminal_ws_url', 'wss://yak.example.test/terminal/ws');

    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $admin = User::factory()->create();
    config(['claude-accounts.admin_user_ids' => [$admin->id]]);
    $thread = Thread::factory()->stopped()->private($owner)->create();
    $address = SandboxSshAddress::for($thread);

    // The owner uploads a key through the settings page.
    $this->actingAs($owner)->post(route('settings.ssh-keys.store'), ['name' => 'Laptop', 'public_key' => journeyKeyLine('me@laptop')])
        ->assertSessionHasNoErrors();
    $key = $owner->sshKeys()->firstOrFail();

    // A stranger's and an admin's keys resolve to no lines for the private thread.
    foreach ([$stranger, $admin] as $person) {
        $other = SshKey::factory()->forUser($person)->create();
        $this->withToken('secret-token')->postJson('/internal/ssh/authorized-keys', ['fingerprint' => $other->fingerprint])
            ->assertExactJson(['lines' => []]);
    }

    // The owner's fingerprint resolves to a permitopen line for the thread's address.
    $this->withToken('secret-token')->postJson('/internal/ssh/authorized-keys', ['fingerprint' => $key->fingerprint])
        ->assertJsonPath('lines.0', 'restrict,port-forwarding,permitopen="' . $address . ':22" ' . $key->public_key);

    // The CLI lookup returns the same address.
    [, $token] = makeDevice($owner);
    $this->getJson("/api/cli/v1/threads/{$thread->id}/ssh", cliHeaders($token))
        ->assertOk()
        ->assertJsonPath('target.host', $address);

    // Waking a stopped sandbox starts it and installs the key.
    $this->withToken('secret-token')->postJson('/internal/ssh/wake', ['address' => $address])
        ->assertOk()->assertJson(['state' => 'ready']);
    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running)
        ->and(array_column(app(SandboxManager::class)->calls, 'operation'))->toBe(['start', 'exec'])
        ->and(journeyLastInstalledKeys())->toContain($key->public_key);

    // A terminal session installs an ephemeral key that the sweep removes after its ttl.
    $origin = 'https://yak.example.test';
    $session = $this->actingAs($owner)->withHeader('Origin', $origin)->postJson("/threads/{$thread->id}/terminal-sessions")->assertOk();
    $ephemeral = journeyKeyLine();
    $this->withToken('secret-token')->postJson('/internal/ssh/terminal/redeem', [
        'token' => $session->json('token'),
        'public_key' => $ephemeral,
        'origin' => $origin,
    ])->assertOk()->assertJsonPath('address', $address);
    expect(journeyLastInstalledKeys())->toContain("restrict,pty {$ephemeral}");

    $this->travel(901)->seconds();
    app(SandboxAuthorizedKeys::class)->sweep();
    expect(journeyLastInstalledKeys())->not->toContain($ephemeral);

    // Making the thread private to someone else removes access on the next lookup.
    $thread->update(['owner_user_id' => $stranger->id]);
    $this->withToken('secret-token')->postJson('/internal/ssh/authorized-keys', ['fingerprint' => $key->fingerprint])
        ->assertExactJson(['lines' => []]);
    $this->getJson("/api/cli/v1/threads/{$thread->id}/ssh", cliHeaders($token))->assertNotFound();
});
