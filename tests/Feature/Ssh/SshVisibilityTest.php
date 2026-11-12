<?php

use App\Models\SshKey;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;

beforeEach(function () {
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
    config()->set('yak.ssh.internal_token', 'secret-token');
    config()->set('yak.ssh.jump_host', 'ssh.example.test');
    config()->set('app.url', 'https://yak.example.test');

    $this->owner = User::factory()->create();
    $this->thread = Thread::factory()->paused()->private($this->owner)->create();
    $this->people = [
        'owner' => $this->owner,
        'other' => User::factory()->create(),
        'admin' => User::factory()->create(),
        'viewer' => User::factory()->create(),
    ];
    config(['claude-accounts.admin_user_ids' => [$this->people['admin']->id]]);
    ThreadShare::factory()->create(['thread_id' => $this->thread->id, 'user_id' => $this->people['viewer']->id]);

    foreach ($this->people as $person) {
        SshKey::factory()->forUser($person)->create();
    }
});

function sshSurfaceStatus(string $surface, User $person, Thread $thread): int
{
    if ($surface === 'terminal') {
        return test()->actingAs($person)->withHeader('Origin', 'https://yak.example.test')
            ->postJson("/threads/{$thread->id}/terminal-sessions")->getStatusCode();
    }

    [, $token] = makeDevice($person);

    return test()->getJson("/api/cli/v1/threads/{$thread->id}/ssh", cliHeaders($token))->getStatusCode();
}

it('shows no private-thread ssh surface to anyone but the owner', function (string $surface, string $person, int $status) {
    expect(sshSurfaceStatus($surface, $this->people[$person], $this->thread))->toBe($status);
})->with([
    'terminal as owner' => ['terminal', 'owner', 200],
    'terminal as other' => ['terminal', 'other', 404],
    'terminal as admin' => ['terminal', 'admin', 404],
    'terminal as viewer' => ['terminal', 'viewer', 403],
    'cli as owner' => ['cli', 'owner', 200],
    'cli as other' => ['cli', 'other', 404],
    'cli as admin' => ['cli', 'admin', 404],
    'cli as viewer' => ['cli', 'viewer', 403],
]);

it('answers a missing thread with the same status as a hidden one', function (string $surface) {
    $missing = new Thread;
    $missing->id = 999_999;

    expect(sshSurfaceStatus($surface, $this->people['other'], $missing))
        ->toBe(sshSurfaceStatus($surface, $this->people['other'], $this->thread));
})->with(['terminal', 'cli']);

it('hands the jump host a line for the owner only', function (string $person, bool $gets) {
    $fingerprint = $this->people[$person]->sshKeys()->firstOrFail()->fingerprint;

    $lines = $this->withToken('secret-token')
        ->postJson('/internal/ssh/authorized-keys', ['fingerprint' => $fingerprint])
        ->assertOk()->json('lines');

    expect($lines === [])->toBe(! $gets);
})->with([
    'owner' => ['owner', true],
    'other' => ['other', false],
    'admin' => ['admin', false],
    'viewer' => ['viewer', false],
]);
