<?php

use App\Enums\ThreadSandboxState;
use App\Models\SshKey;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Services\Ssh\SandboxSshAddress;
use App\Services\Ssh\SshAccess;

function keyedUser(): User
{
    $user = User::factory()->create();
    SshKey::factory()->forUser($user)->create();

    return $user;
}

function lineFor(User $user): array
{
    return app(SshAccess::class)->authorizedKeyLines($user->sshKeys()->firstOrFail()->fingerprint);
}

it('round trips an address and rejects ids it cannot encode', function () {
    $thread = Thread::factory()->paused()->create();
    $address = SandboxSshAddress::for($thread);

    expect($address)->toMatch('/^127\.\d+\.\d+\.\d+$/')
        ->and(SandboxSshAddress::threadFor($address)?->id)->toBe($thread->id)
        ->and(SandboxSshAddress::threadFor('127.0.0.1'))->toBeNull()
        ->and(SandboxSshAddress::threadFor('10.1.2.3'))->toBeNull()
        ->and(SandboxSshAddress::threadFor('nonsense'))->toBeNull();

    $big = new Thread;
    $big->id = 4_194_303;
    expect(SandboxSshAddress::for($big))->toBe('127.79.255.255');

    $big->id = 4_194_304;
    SandboxSshAddress::for($big);
})->throws(OutOfRangeException::class);

it('encodes ids as documented', function () {
    $thread = new Thread;
    $thread->id = 0x04_12_34;

    expect(SandboxSshAddress::for($thread))->toBe('127.20.18.52');
});

it('gives any keyed user a line for a live team thread', function () {
    $thread = Thread::factory()->paused()->create();
    $user = keyedUser();

    $lines = lineFor($user);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toStartWith('restrict,port-forwarding,permitopen="' . SandboxSshAddress::for($thread) . ':22" ssh-ed25519 ');
});

it('lists several sandboxes on one line', function () {
    $first = Thread::factory()->paused()->create();
    $second = Thread::factory()->stopped()->create();

    $lines = lineFor(keyedUser());

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toContain('permitopen="' . SandboxSshAddress::for($first) . ':22"')
        ->and($lines[0])->toContain('permitopen="' . SandboxSshAddress::for($second) . ':22"');
});

it('gives only the owner a line for a private thread', function () {
    $owner = keyedUser();
    $other = keyedUser();
    $admin = keyedUser();
    config(['claude-accounts.admin_user_ids' => [$admin->id]]);
    Thread::factory()->paused()->private($owner)->create();

    expect(lineFor($owner))->toHaveCount(1)
        ->and(lineFor($other))->toBe([])
        ->and(lineFor($admin))->toBe([]);
});

it('gives nothing to a user a private thread is only shared with', function () {
    $owner = keyedUser();
    $viewer = keyedUser();
    $thread = Thread::factory()->paused()->private($owner)->create();
    ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

    expect(lineFor($viewer))->toBe([]);
});

it('excludes closed, device-owned and destroyed threads', function () {
    $user = keyedUser();
    Thread::factory()->paused()->closed()->create();
    Thread::factory()->paused()->ownedByDevice()->create();
    Thread::factory()->destroyed()->create();
    Thread::factory()->create();

    expect(lineFor($user))->toBe([]);
});

it('resolves a sub-thread through its root', function () {
    $owner = keyedUser();
    $other = keyedUser();
    $root = Thread::factory()->private($owner)->create();
    $child = Thread::factory()->child($root)->paused()->create();

    expect(lineFor($owner)[0])->toContain(SandboxSshAddress::for($child))
        ->and(lineFor($other))->toBe([]);
});

it('returns nothing for an unknown fingerprint', function () {
    Thread::factory()->paused()->create();

    expect(app(SshAccess::class)->authorizedKeyLines('SHA256:unknown'))->toBe([]);
});

it('caps the sandboxes on one line at 200', function () {
    $user = keyedUser();
    Thread::factory()->count(201)->paused()->create();

    expect(substr_count(lineFor($user)[0], 'permitopen='))->toBe(200);
});

it('reports why a shell is refused', function (Closure $make, ?string $denial) {
    [$user, $thread] = $make();

    expect(app(SshAccess::class)->denial($user, $thread))->toBe($denial)
        ->and(app(SshAccess::class)->canOpenShell($user, $thread))->toBe($denial === null);
})->with([
    'ok' => [fn () => [User::factory()->create(), Thread::factory()->paused()->create()], null],
    'anonymous' => [fn () => [null, Thread::factory()->paused()->create()], 'not_found'],
    'unseen' => [fn () => [User::factory()->create(), Thread::factory()->paused()->private()->create()], 'not_found'],
    'closed' => [fn () => [User::factory()->create(), Thread::factory()->paused()->closed()->create()], 'thread_closed'],
    'device' => [fn () => [User::factory()->create(), Thread::factory()->paused()->ownedByDevice()->create()], 'thread_owned_by_device'],
    'destroyed' => [fn () => [User::factory()->create(), Thread::factory()->destroyed()->create()], 'sandbox_destroyed'],
    'none' => [fn () => [User::factory()->create(), Thread::factory()->create()], 'no_sandbox'],
    'shared' => [function () {
        $viewer = User::factory()->create();
        $thread = Thread::factory()->paused()->private()->create();
        ThreadShare::factory()->create(['thread_id' => $thread->id, 'user_id' => $viewer->id]);

        return [$viewer, $thread];
    }, 'forbidden'],
]);

it('lists the drivers of a thread who have keys', function () {
    $owner = keyedUser();
    $keyed = keyedUser();
    User::factory()->create();

    $private = Thread::factory()->paused()->private($owner)->create();
    $team = Thread::factory()->paused()->create();

    expect(app(SshAccess::class)->drivers($private)->pluck('id')->all())->toBe([$owner->id])
        ->and(app(SshAccess::class)->drivers($team)->pluck('id')->sort()->values()->all())->toBe([$owner->id, $keyed->id]);
});

it('keeps a thread with a starting sandbox reachable', function () {
    $thread = Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Starting]);

    expect(app(SshAccess::class)->canOpenShell(User::factory()->create(), $thread))->toBeTrue();
});
