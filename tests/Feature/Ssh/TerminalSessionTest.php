<?php

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Services\Ssh\SandboxAuthorizedKeys;
use App\Services\Ssh\SandboxSshAddress;
use App\Services\Threads\Contracts\SandboxManager;

const TERMINAL_ORIGIN = 'https://yak.example.test';

beforeEach(function () {
    config()->set('app.url', TERMINAL_ORIGIN);
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
    config()->set('yak.ssh.internal_token', 'secret-token');
    config()->set('yak.ssh.terminal_ws_url', 'wss://yak.example.test/terminal/ws');
});

function openTerminal(User $user, int $threadId, string $origin = TERMINAL_ORIGIN)
{
    return test()->actingAs($user)->withHeader('Origin', $origin)->postJson("/threads/{$threadId}/terminal-sessions");
}

function terminalKeyLine(): string
{
    return 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . random_bytes(32));
}

function redeemTerminal(string $token, ?string $publicKey = null, string $origin = TERMINAL_ORIGIN)
{
    return test()->withToken('secret-token')->postJson('/internal/ssh/terminal/redeem', [
        'token' => $token,
        'public_key' => $publicKey ?? terminalKeyLine(),
        'origin' => $origin,
    ]);
}

function liveTeamThread(): Thread
{
    return Thread::factory()->paused()->create();
}

it('issues a token and wakes the sandbox', function () {
    $thread = liveTeamThread();
    $user = User::factory()->create();

    $response = openTerminal($user, $thread->id)->assertOk();

    expect($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and($response->json('url'))->toBe('wss://yak.example.test/terminal/ws?token=' . $response->json('token'))
        ->and($response->json('expires_at'))->toBeString()
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

it('stores only a hash of the token', function () {
    $token = openTerminal(User::factory()->create(), liveTeamThread()->id)->json('token');

    expect(Cache::get('ssh:terminal-token:' . $token))->toBeNull()
        ->and(Cache::get('ssh:terminal-token:' . hash('sha256', $token)))->toBeArray();
});

it('answers 202 waiting when no sandbox slot is free', function () {
    config(['yak.threads.awake_cap' => 1]);
    Thread::factory()->paused()->create(['sandbox_state' => ThreadSandboxState::Running]);
    $thread = Thread::factory()->stopped()->create();

    openTerminal(User::factory()->create(), $thread->id)->assertStatus(202)->assertExactJson(['state' => 'waiting']);
});

it('refuses a viewer who cannot drive with 403', function () {
    $viewer = User::factory()->create();
    $thread = Thread::factory()->paused()->private()->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    openTerminal($viewer, $thread->id)->assertForbidden()->assertJson(['error' => 'forbidden']);
});

it('answers a hidden private thread exactly like a missing one', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $admin = User::factory()->create();
    config(['claude-accounts.admin_user_ids' => [$admin->id]]);
    $thread = Thread::factory()->paused()->private($owner)->create();

    $missing = openTerminal($other, 999_999);

    foreach ([$other, $admin] as $person) {
        $hidden = openTerminal($person, $thread->id);
        expect($hidden->getStatusCode())->toBe(404)->and($hidden->json('message'))->toBe($missing->json('message'));
    }

    openTerminal($owner, $thread->id)->assertOk();
});

it('maps unavailable threads to 409 codes', function (Closure $make, string $error) {
    openTerminal(User::factory()->create(), $make()->id)->assertStatus(409)->assertJson(['error' => $error]);
})->with([
    'closed' => [fn () => Thread::factory()->paused()->closed()->create(), 'thread_closed'],
    'device' => [fn () => Thread::factory()->paused()->ownedByDevice()->create(), 'thread_owned_by_device'],
    'destroyed' => [fn () => Thread::factory()->destroyed()->create(), 'sandbox_destroyed'],
]);

it('refuses an origin that is not allowed', function () {
    $thread = liveTeamThread();
    $user = User::factory()->create();

    openTerminal($user, $thread->id, 'https://evil.example.test')->assertForbidden();
    test()->actingAs($user)->postJson("/threads/{$thread->id}/terminal-sessions")->assertForbidden();

    config(['yak.ssh.terminal_allowed_origins' => ['https://alt.example.test']]);
    openTerminal($user, $thread->id, 'https://alt.example.test')->assertOk();
});

it('redeems a token once and installs the key', function () {
    $thread = liveTeamThread();
    $token = openTerminal(User::factory()->create(), $thread->id)->json('token');
    $line = terminalKeyLine();

    redeemTerminal($token, $line)->assertOk()->assertExactJson([
        'thread_id' => $thread->id,
        'address' => SandboxSshAddress::for($thread),
        'container' => $thread->sandbox_container,
        'ttl' => 900,
    ]);

    $installed = collect(app(SandboxManager::class)->calls)->where('operation', 'exec')->last()['arguments']['stdin'];
    expect($installed)->toContain("restrict,pty {$line}");

    redeemTerminal($token)->assertForbidden();
});

it('refuses an expired token', function () {
    $token = openTerminal(User::factory()->create(), liveTeamThread()->id)->json('token');

    $this->travel(61)->seconds();

    redeemTerminal($token)->assertForbidden()->assertContent('');
});

it('refuses a token redeemed from another origin and burns it', function () {
    $token = openTerminal(User::factory()->create(), liveTeamThread()->id)->json('token');

    redeemTerminal($token, origin: 'https://evil.example.test')->assertForbidden();
    redeemTerminal($token)->assertForbidden();
});

it('opens only the thread the token was issued for', function () {
    $first = liveTeamThread();
    $second = liveTeamThread();
    $token = openTerminal(User::factory()->create(), $first->id)->json('token');

    redeemTerminal($token)->assertJsonPath('thread_id', $first->id);

    $execs = collect(app(SandboxManager::class)->calls)->where('operation', 'exec')->pluck('threadId')->unique()->all();
    expect($execs)->not->toContain($second->id);
});

it('refuses a redeem after the thread turned private', function () {
    $thread = liveTeamThread();
    $token = openTerminal(User::factory()->create(), $thread->id)->json('token');

    $thread->update(['visibility' => 'private', 'owner_user_id' => User::factory()->create()->id]);

    redeemTerminal($token)->assertForbidden()->assertContent('');
});

it('refuses a bad public key and a missing internal token', function () {
    $token = openTerminal(User::factory()->create(), liveTeamThread()->id)->json('token');

    redeemTerminal($token, 'not a key')->assertForbidden();

    $token = openTerminal(User::factory()->create(), liveTeamThread()->id)->json('token');
    $this->withToken('wrong')->postJson('/internal/ssh/terminal/redeem', ['token' => $token, 'public_key' => terminalKeyLine(), 'origin' => TERMINAL_ORIGIN])->assertUnauthorized();
});

it('sweeps the short-lived key after its ttl', function () {
    $thread = liveTeamThread();
    $token = openTerminal(User::factory()->create(), $thread->id)->json('token');
    $line = terminalKeyLine();
    redeemTerminal($token, $line)->assertOk();
    $thread->update(['sandbox_state' => ThreadSandboxState::Running]);

    $this->travel(901)->seconds();
    app(SandboxAuthorizedKeys::class)->sweep();

    $installed = collect(app(SandboxManager::class)->calls)->where('operation', 'exec')->last()['arguments']['stdin'];
    expect($installed)->not->toContain($line);
});
