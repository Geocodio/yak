<?php

use App\Models\SshKey;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Services\Ssh\SandboxSshAddress;

beforeEach(function () {
    config()->set('yak.ssh.jump_host', 'ssh.example.test');
    [$this->device, $this->token] = makeDevice();
    $this->user = $this->device->user;
});

function sshLookup(int $threadId)
{
    return test()->getJson("/api/cli/v1/threads/{$threadId}/ssh", cliHeaders(test()->token));
}

it('answers the contract body for a driver with a key', function () {
    SshKey::factory()->forUser($this->user)->create();
    $thread = Thread::factory()->paused()->create();

    sshLookup($thread->id)->assertOk()->assertExactJson([
        'jump_host' => ['host' => 'ssh.example.test', 'port' => 2222, 'user' => 'yak-jump'],
        'alias' => $thread->name,
        'target' => ['host' => SandboxSshAddress::for($thread), 'port' => 22, 'user' => 'yak'],
        'host_key_fingerprints' => [],
    ]);
});

it('answers 409 no_ssh_key with the settings url', function () {
    $thread = Thread::factory()->paused()->create();

    sshLookup($thread->id)->assertStatus(409)
        ->assertJsonPath('error', 'no_ssh_key')
        ->assertJsonPath('details.settings_url', route('settings.ssh-keys'));
});

it('answers 409 thread_owned_by_device', function () {
    sshLookup(Thread::factory()->paused()->ownedByDevice()->create()->id)
        ->assertStatus(409)->assertJsonPath('error', 'thread_owned_by_device');
});

it('answers 403 for a visible thread the caller cannot drive', function () {
    $thread = Thread::factory()->paused()->private()->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $this->user->id]);

    sshLookup($thread->id)->assertForbidden()->assertJsonPath('error', 'forbidden');
});

it('answers 409 sandbox_destroyed with the thread url', function () {
    $thread = Thread::factory()->destroyed()->create();

    sshLookup($thread->id)->assertStatus(409)
        ->assertJsonPath('error', 'sandbox_destroyed')
        ->assertJsonPath('details.thread_url', route('threads.show', $thread));
});

it('answers 409 for a closed thread and a thread without a sandbox', function () {
    sshLookup(Thread::factory()->paused()->closed()->create()->id)->assertStatus(409)->assertJsonPath('error', 'thread_closed');
    sshLookup(Thread::factory()->create()->id)->assertStatus(409)->assertJsonPath('error', 'no_sandbox');
});

it('answers 503 when no jump host is configured', function () {
    config()->set('yak.ssh.jump_host', null);
    SshKey::factory()->forUser($this->user)->create();

    sshLookup(Thread::factory()->paused()->create()->id)->assertStatus(503)->assertJsonPath('error', 'ssh_unavailable');
});

it('hides a private thread from a second user and an admin like a missing one', function () {
    $thread = Thread::factory()->paused()->private()->create();
    $missing = sshLookup(999_999);

    $missing->assertNotFound()->assertJsonPath('error', 'thread_not_found');
    sshLookup($thread->id)->assertNotFound()->assertExactJson($missing->json());

    config(['claude-accounts.admin_user_ids' => [$this->user->id]]);
    sshLookup($thread->id)->assertNotFound()->assertExactJson($missing->json());
});

it('requires a device token', function () {
    $this->getJson('/api/cli/v1/threads/1/ssh', ['X-Yak-Protocol' => '1'])->assertUnauthorized();
});
