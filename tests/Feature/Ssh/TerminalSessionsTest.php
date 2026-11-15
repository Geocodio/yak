<?php

use App\DataTransferObjects\SandboxWake;
use App\DataTransferObjects\TerminalSessionResult;
use App\Enums\EvictionReason;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Services\Ssh\TerminalSessions;
use App\Services\Ssh\TerminalTokens;
use App\Services\Threads\SandboxCapacity;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\Http;

const SESSIONS_ORIGIN = 'https://yak.example.test';

beforeEach(function () {
    config()->set('app.url', SESSIONS_ORIGIN);
    config()->set('yak.ssh.terminal_ws_url', 'wss://yak.example.test/terminal/ws');
});

function sessions(): TerminalSessions
{
    return app(TerminalSessions::class);
}

function mockSandbox(?SandboxWake $wake): void
{
    $mock = Mockery::mock(ThreadSandbox::class);

    if ($wake === null) {
        $mock->shouldNotReceive('ensureAwake');
    } else {
        $mock->shouldReceive('ensureAwake')->andReturn($wake);
    }

    app()->instance(ThreadSandbox::class, $mock);
}

it('opens a shell in an awake sandbox', function () {
    $thread = Thread::factory()->paused()->create(['sandbox_state' => ThreadSandboxState::Running]);
    $user = User::factory()->create();

    $result = sessions()->open($user, $thread, SESSIONS_ORIGIN);

    expect($result->outcome)->toBe(TerminalSessionResult::READY)
        ->and($result->url)->toBe('wss://yak.example.test/terminal/ws?token=' . $result->token);
    expect(app(TerminalTokens::class)->redeem($result->token, SESSIONS_ORIGIN))->not->toBeNull();
    expect(app(TerminalTokens::class)->redeem($result->token, SESSIONS_ORIGIN))->toBeNull();
});

it('refuses a foreign origin before anything else', function () {
    mockSandbox(null);

    $result = sessions()->open(User::factory()->create(), Thread::factory()->paused()->create(), 'https://other.example.test');

    expect($result->outcome)->toBe(TerminalSessionResult::REFUSED)->and($result->refusal)->toBe('origin_not_allowed');
});

it('maps every denial to its code', function (string $code, Closure $make) {
    mockSandbox(null);
    [$user, $thread] = $make();

    $result = sessions()->open($user, $thread, SESSIONS_ORIGIN);

    expect($result->outcome)->toBe(TerminalSessionResult::REFUSED)->and($result->refusal)->toBe($code);
})->with([
    'forbidden' => ['forbidden', function () {
        $viewer = User::factory()->create();
        $thread = Thread::factory()->paused()->private()->create();
        ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

        return [$viewer, $thread];
    }],
    'thread_closed' => ['thread_closed', fn () => [User::factory()->create(), Thread::factory()->paused()->closed()->create()]],
    'thread_owned_by_device' => ['thread_owned_by_device', fn () => [User::factory()->create(), Thread::factory()->ownedByDevice()->create()]],
    'sandbox_destroyed' => ['sandbox_destroyed', fn () => [User::factory()->create(), Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Destroyed])]],
    'no_sandbox' => ['no_sandbox', fn () => [User::factory()->create(), Thread::factory()->create(['sandbox_state' => ThreadSandboxState::None])]],
]);

it('waits while a restore is queued', function () {
    mockSandbox(null);
    $destroyed = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Destroyed, 'restore_requested_at' => now()]);
    $failed = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'blocked_reason' => ThreadBlockReason::RestoreFailed, 'restore_requested_at' => now()]);

    foreach ([$destroyed, $failed] as $thread) {
        $result = sessions()->open(User::factory()->create(), $thread, SESSIONS_ORIGIN, false);

        expect($result->outcome)->toBe(TerminalSessionResult::WAITING)->and($result->limit)->toBeNull()->and($result->restoring)->toBeTrue();
    }
});

it('names the slot a queued restore waits for', function () {
    mockSandbox(null);
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Destroyed, 'restore_requested_at' => now(), 'blocked_reason' => ThreadBlockReason::WaitingForSandbox]);
    $capacity = Mockery::mock(SandboxCapacity::class);
    $capacity->shouldReceive('needsEviction')->andReturn(EvictionReason::PersonShare);
    app()->instance(SandboxCapacity::class, $capacity);

    $result = sessions()->open(User::factory()->create(), $thread, SESSIONS_ORIGIN);

    expect($result->limit)->toBe('person_share')->and($result->restoring)->toBeTrue();
});

it('treats an expired guard as no restore', function () {
    mockSandbox(null);
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Destroyed, 'restore_requested_at' => now()->subMinutes(31)]);

    expect(sessions()->open(User::factory()->create(), $thread, SESSIONS_ORIGIN)->refusal)->toBe('sandbox_destroyed');
});

it('reports a failed restore without rebuilding inline', function (ThreadSandboxState $state) {
    mockSandbox(null);
    $thread = Thread::factory()->create(['sandbox_state' => $state, 'blocked_reason' => ThreadBlockReason::RestoreFailed]);

    $result = sessions()->open(User::factory()->create(), $thread, SESSIONS_ORIGIN, false);

    expect($result->outcome)->toBe(TerminalSessionResult::RESTORE_FAILED);
})->with([ThreadSandboxState::Destroyed, ThreadSandboxState::None, ThreadSandboxState::Running]);

it('keeps the denial for the web when a restore failed', function () {
    mockSandbox(null);
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Destroyed, 'blocked_reason' => ThreadBlockReason::RestoreFailed]);

    expect(sessions()->open(User::factory()->create(), $thread, SESSIONS_ORIGIN, true)->refusal)->toBe('sandbox_destroyed');
});

it('says restoring while the wake rebuilds and not while it waits for a slot', function () {
    $thread = Thread::factory()->paused()->create();
    $user = User::factory()->create();

    mockSandbox(SandboxWake::restoring());
    expect(sessions()->open($user, $thread, SESSIONS_ORIGIN)->restoring)->toBeTrue();

    mockSandbox(SandboxWake::waitingForSlot());
    $result = sessions()->open($user, $thread, SESSIONS_ORIGIN);
    expect($result->outcome)->toBe(TerminalSessionResult::WAITING)->and($result->restoring)->toBeFalse();
});

it('reports a failed rebuild', function () {
    mockSandbox(new SandboxWake(false, false, false));

    expect(sessions()->open(User::factory()->create(), Thread::factory()->paused()->create(), SESSIONS_ORIGIN)->outcome)->toBe(TerminalSessionResult::RESTORE_FAILED);
});

it('names the full cap while waiting for a slot', function (?EvictionReason $reason) {
    mockSandbox(SandboxWake::waitingForSlot());
    $capacity = Mockery::mock(SandboxCapacity::class);
    $capacity->shouldReceive('needsEviction')->andReturn($reason);
    app()->instance(SandboxCapacity::class, $capacity);

    $result = sessions()->open(User::factory()->create(), Thread::factory()->paused()->create(), SESSIONS_ORIGIN);

    expect($result->limit)->toBe($reason?->value);
})->with([...EvictionReason::cases(), null]);

it('never calls GitHub', function () {
    Http::fake();
    mockSandbox(SandboxWake::ready());

    sessions()->open(User::factory()->create(), Thread::factory()->paused()->create(), SESSIONS_ORIGIN);

    Http::assertNothingSent();
});

it('builds the socket URL from config or the app URL', function () {
    expect(TerminalSessions::socketUrl())->toBe('wss://yak.example.test/terminal/ws');

    config()->set('yak.ssh.terminal_ws_url', '');

    expect(TerminalSessions::socketUrl())->toBe('wss://yak.example.test/terminal/ws');
});
