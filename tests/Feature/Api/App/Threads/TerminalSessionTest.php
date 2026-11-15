<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\EvictionReason;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadShareMode;
use App\Enums\ThreadStatus;
use App\Jobs\RetryThreadRestoreJob;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\SandboxCapacity;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadSharing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\ContractFixture;

const APP_TERMINAL_ORIGIN = 'https://yak.example.test';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:14:00'));
    config()->set('app.url', APP_TERMINAL_ORIGIN);
    config()->set('yak.ssh.terminal_ws_url', 'wss://yak.example.test/terminal/ws');
    config()->set('yak.ssh.internal_token', 'secret-token');
    config()->set('yak.deployments.internal.ingress_ip_cidr', '0.0.0.0/0');
});

/**
 * @param  array<string, mixed>  $state
 * @return array{0: Thread, 1: User}
 */
function terminalThread(array $state = []): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($user, ['title' => 'Retry failed uploads', 'repo' => 'example/api', 'sandbox_container' => 'yak-thread-7', 'sandbox_state' => ThreadSandboxState::Running, ...$state]);

    return [$thread, $user];
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>|null  $headers
 */
function openAppTerminal(Thread $thread, User $user, ?string $origin = APP_TERMINAL_ORIGIN, array $body = [], ?array $headers = null): TestResponse
{
    $headers ??= phoneHeadersFor($user);

    return test()->postJson(appThreadPath($thread, '/terminal-sessions'), $body, $origin === null ? $headers : [...$headers, 'Origin' => $origin]);
}

function mockEnsureAwake(?SandboxWake $wake): void
{
    $mock = Mockery::mock(ThreadSandbox::class);

    if ($wake === null) {
        $mock->shouldNotReceive('ensureAwake');
    } else {
        $mock->shouldReceive('ensureAwake')->andReturn($wake);
    }

    app()->instance(ThreadSandbox::class, $mock);
}

function mockEviction(?EvictionReason $reason): void
{
    app()->instance(SandboxCapacity::class, Mockery::mock(SandboxCapacity::class)->shouldReceive('needsEviction')->andReturn($reason)->getMock());
}

it('opens a shell for the owner', function () {
    [$thread, $user] = terminalThread();

    $response = openAppTerminal($thread, $user)->assertOk();
    $token = $response->json('token');

    expect($response->json('url'))->toBe('wss://yak.example.test/terminal/ws?token=' . $token)
        ->and($response->json('expires_at'))->toBe(now()->addSeconds(60)->toIso8601String());

    $redeem = fn () => $this->withToken('secret-token')->postJson('/internal/ssh/terminal/redeem', [
        'token' => $token,
        'public_key' => 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat('k', 32)),
        'origin' => APP_TERMINAL_ORIGIN,
    ]);
    $redeem()->assertOk();
    $redeem()->assertForbidden();

    ContractFixture::assertMatches('app', 'thread-terminal-session', $response->json(), ['url', 'token', 'expires_at']);
});

it('asks the phone to wait for a slot', function () {
    [$thread, $user] = terminalThread(['sandbox_state' => ThreadSandboxState::Paused]);
    mockEnsureAwake(SandboxWake::waitingForSlot());
    mockEviction(EvictionReason::GlobalCap);

    $response = openAppTerminal($thread, $user)->assertStatus(202)->assertExactJson(['state' => 'waiting', 'limit' => 'global_cap', 'restoring' => false]);

    ContractFixture::assertMatches('app', 'thread-terminal-session-waiting', $response->json());
});

it('says the sandbox was removed', function () {
    [$thread, $user] = terminalThread(['sandbox_state' => ThreadSandboxState::Destroyed, 'sandbox_container' => null]);

    $response = openAppTerminal($thread, $user)->assertStatus(409)->assertExactJson(['error' => 'sandbox_destroyed', 'message' => 'The sandbox was removed. Restore it to open a shell.']);

    ContractFixture::assertMatches('app', 'error-sandbox-destroyed', $response->json());
});

it('answers each refusal with its code and sentence', function (string $case, int $status, string $code, string $message) {
    [$thread, $user] = terminalThread();
    $origin = APP_TERMINAL_ORIGIN;
    $headers = null;

    match ($case) {
        'origin missing' => $origin = null,
        'origin foreign' => $origin = 'https://other.example.test',
        'share recipient' => (function () use ($thread, $user, &$headers): void {
            $recipient = User::factory()->create();
            app(ThreadSharing::class)->apply($thread, $user, ThreadShareMode::People, [$recipient->id]);
            $headers = phoneHeadersFor($recipient);
        })(),
        'closed' => $thread->update(['status' => ThreadStatus::Closed, 'closed_at' => now()]),
        'on a computer' => $thread->update(['owner_side' => ThreadOwnerSide::Device]),
        'no sandbox' => $thread->update(['sandbox_state' => ThreadSandboxState::None, 'sandbox_container' => null]),
    };

    openAppTerminal($thread, $user, $origin, headers: $headers)->assertStatus($status)->assertExactJson(['error' => $code, 'message' => $message]);
})->with([
    ['origin missing', 403, 'origin_not_allowed', 'This Yak does not accept terminal connections from this app.'],
    ['origin foreign', 403, 'origin_not_allowed', 'This Yak does not accept terminal connections from this app.'],
    ['share recipient', 403, 'forbidden', 'You do not have access to this.'],
    ['closed', 409, 'thread_closed', 'This thread is closed.'],
    ['on a computer', 409, 'thread_owned_by_device', 'This thread runs on a computer.'],
    ['no sandbox', 409, 'no_sandbox', 'This thread has no sandbox yet.'],
]);

it('never wakes a paused sandbox for a share recipient', function () {
    [$thread, $user] = terminalThread(['sandbox_state' => ThreadSandboxState::Stopped]);
    $recipient = User::factory()->create();
    app(ThreadSharing::class)->apply($thread, $user, ThreadShareMode::People, [$recipient->id]);
    mockEnsureAwake(null);

    openAppTerminal($thread, $user, headers: phoneHeadersFor($recipient))->assertStatus(403)->assertJsonPath('error', 'forbidden');
});

it('answers a failed restore as blocked', function (ThreadSandboxState $state) {
    [$thread, $user] = terminalThread(['sandbox_state' => $state, 'blocked_reason' => ThreadBlockReason::RestoreFailed]);
    mockEnsureAwake(null);

    openAppTerminal($thread, $user)->assertStatus(409)->assertExactJson([
        'error' => 'thread_blocked',
        'message' => 'The sandbox could not be restored.',
        'details' => ['reason' => 'restore_failed'],
    ]);
})->with([ThreadSandboxState::Destroyed, ThreadSandboxState::Running]);

it('waits while Restore runs', function () {
    Queue::fake();
    [$thread, $user] = terminalThread(['sandbox_state' => ThreadSandboxState::Destroyed, 'sandbox_container' => null]);

    $this->postJson(appThreadPath($thread, '/restore/retry'), [], phoneHeadersFor($user))->assertOk();
    Queue::assertPushed(RetryThreadRestoreJob::class, 1);

    openAppTerminal($thread, $user)->assertStatus(202)->assertExactJson(['state' => 'waiting', 'limit' => null, 'restoring' => true]);
});

it('names the slot a queued restore waits for', function () {
    [$thread, $user] = terminalThread(['sandbox_state' => ThreadSandboxState::Destroyed, 'sandbox_container' => null, 'blocked_reason' => ThreadBlockReason::WaitingForSandbox, 'restore_requested_at' => now()]);
    mockEviction(EvictionReason::GlobalCap);

    openAppTerminal($thread, $user)->assertStatus(202)->assertExactJson(['state' => 'waiting', 'limit' => 'global_cap', 'restoring' => true]);
});

it('answers a stranger like a missing thread', function () {
    [$thread] = terminalThread();
    $stranger = User::factory()->create();
    $missing = openAppTerminal($thread, $stranger)->assertNotFound();

    $missingBody = test()->postJson('/api/app/v1/threads/zzzzzzzz/terminal-sessions', [], [...phoneHeadersFor($stranger), 'Origin' => APP_TERMINAL_ORIGIN])->assertNotFound()->json();

    expect($missing->json())->toBe($missingBody);
    test()->postJson('/api/app/v1/threads/' . $thread->id . '/terminal-sessions', [], [...phoneHeadersFor($stranger), 'Origin' => APP_TERMINAL_ORIGIN])->assertNotFound()->assertExactJson($missingBody);
});

it('refuses a missing, revoked or computer token', function () {
    [$thread, $user] = terminalThread();
    $headers = ['Origin' => APP_TERMINAL_ORIGIN, 'X-Yak-Protocol' => '1'];

    $this->postJson(appThreadPath($thread, '/terminal-sessions'), [], $headers)->assertUnauthorized();

    [$device, $phoneHeaders] = phoneWithHeaders(['user_id' => $user->id]);
    $device->forceFill(['revoked_at' => now()])->save();
    openAppTerminal($thread, $user, headers: $phoneHeaders)->assertUnauthorized();

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $user->id], isPhone: false);
    openAppTerminal($thread, $user, headers: $computerHeaders)->assertUnauthorized();
});

it('ignores unknown fields', function () {
    [$thread, $user] = terminalThread();

    openAppTerminal($thread, $user, body: ['session' => 'main', 'x' => 1])->assertOk()->assertJsonStructure(['url', 'token', 'expires_at']);
});
