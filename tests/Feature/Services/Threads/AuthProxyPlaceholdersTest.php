<?php

use App\Enums\ThreadSandboxState;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\Threads\AuthProxyPlaceholders;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->placeholders = new AuthProxyPlaceholders;
});

it('issues a yakph placeholder of 49 chars', function () {
    $placeholder = $this->placeholders->issue(Thread::factory()->create(), ClaudeAccount::factory()->create(), '10.100.0.5');

    expect($placeholder)->toStartWith('yakph-')->toHaveLength(49);
});

it('resolves an issued thread placeholder to the account from the bound container ip', function () {
    $account = ClaudeAccount::factory()->create();
    $placeholder = $this->placeholders->issue(Thread::factory()->create(), $account, '10.100.0.5');

    expect($this->placeholders->resolve($placeholder, '10.100.0.5')?->is($account))->toBeTrue();
});

it('resolves a placeholder issued for a run id string', function () {
    $account = ClaudeAccount::factory()->create();
    $placeholder = $this->placeholders->issue('run:42', $account, '10.100.0.6');

    expect($this->placeholders->resolve($placeholder, '10.100.0.6')?->is($account))->toBeTrue();
});

it('stores neither the placeholder nor the secret', function () {
    $account = ClaudeAccount::factory()->create();
    $placeholder = $this->placeholders->issue('run:1', $account, '10.100.0.6');
    $store = Cache::store(config('yak.threads.placeholder_store'));

    $stored = $store->get('auth-proxy-placeholder:' . hash('sha256', $placeholder));

    expect($stored)->toBeArray()
        ->and(json_encode($stored))->not->toContain($placeholder)->not->toContain($account->secret)
        ->and($store->get('auth-proxy-placeholder:' . $placeholder))->toBeNull();
});

it('refuses another container ip', function () {
    $placeholder = $this->placeholders->issue('run:1', ClaudeAccount::factory()->create(), '10.100.0.6');

    expect($this->placeholders->resolve($placeholder, '10.100.0.7'))->toBeNull();
});

it('refuses an unknown placeholder and a wrong prefix', function () {
    $placeholder = $this->placeholders->issue('run:1', ClaudeAccount::factory()->create(), '10.100.0.6');

    expect($this->placeholders->resolve('yakph-nope', '10.100.0.6'))->toBeNull()
        ->and($this->placeholders->resolve(substr_replace($placeholder, 'other-', 0, 6), '10.100.0.6'))->toBeNull();
});

it('returns null once the account row is deleted', function () {
    $account = ClaudeAccount::factory()->create();
    $placeholder = $this->placeholders->issue('run:1', $account, '10.100.0.6');
    $account->delete();

    expect($this->placeholders->resolve($placeholder, '10.100.0.6'))->toBeNull();
});

it('returns null once the thread is closed', function () {
    $thread = Thread::factory()->create();
    $placeholder = $this->placeholders->issue($thread, ClaudeAccount::factory()->create(), '10.100.0.6');
    $thread->forceFill(['status' => 'closed'])->save();

    expect($this->placeholders->resolve($placeholder, '10.100.0.6'))->toBeNull();
});

it('still resolves an invalid account', function () {
    $account = ClaudeAccount::factory()->create(['status' => 'invalid']);
    $placeholder = $this->placeholders->issue('run:1', $account, '10.100.0.6');

    expect($this->placeholders->resolve($placeholder, '10.100.0.6')?->is($account))->toBeTrue();
});

it('revokes the previous placeholder when issuing again for the same thread', function () {
    $thread = Thread::factory()->create();
    $account = ClaudeAccount::factory()->create();
    $first = $this->placeholders->issue($thread, $account, '10.100.0.6');
    $second = $this->placeholders->issue($thread, $account, '10.100.0.6');

    expect($this->placeholders->resolve($first, '10.100.0.6'))->toBeNull()
        ->and($this->placeholders->resolve($second, '10.100.0.6'))->not->toBeNull();
});

it('stops resolving after revoke by thread', function () {
    $thread = Thread::factory()->create();
    $placeholder = $this->placeholders->issue($thread, ClaudeAccount::factory()->create(), '10.100.0.6');
    $this->placeholders->revoke($thread);

    expect($this->placeholders->resolve($placeholder, '10.100.0.6'))->toBeNull();
});

it('stops resolving after revoke by run id string', function () {
    $placeholder = $this->placeholders->issue('run:9', ClaudeAccount::factory()->create(), '10.100.0.6');
    $this->placeholders->revoke('run:9');

    expect($this->placeholders->resolve($placeholder, '10.100.0.6'))->toBeNull();
});

it('treats revoking an unknown subject as a no-op', function () {
    $this->placeholders->revoke('run:never');
    $this->placeholders->revoke(Thread::factory()->create());

    expect(true)->toBeTrue();
});

it('keeps two threads independent', function () {
    $account = ClaudeAccount::factory()->create();
    $threadA = Thread::factory()->create();
    $threadB = Thread::factory()->create();
    $a = $this->placeholders->issue($threadA, $account, '10.100.0.6');
    $b = $this->placeholders->issue($threadB, $account, '10.100.0.7');
    $this->placeholders->revoke($threadA);

    expect($this->placeholders->resolve($a, '10.100.0.6'))->toBeNull()
        ->and($this->placeholders->resolve($b, '10.100.0.7'))->not->toBeNull();
});

it('keeps one live placeholder per thread and resolves a new one immediately', function () {
    $thread = Thread::factory()->create(['sandbox_container' => 'yak-thread-1']);
    $account = ClaudeAccount::factory()->create();
    $first = $this->placeholders->issue($thread, $account, '10.100.0.6');
    $thread->forceFill(['runner_generation' => 1])->save();
    $second = $this->placeholders->issue($thread, $account, '10.100.0.6');

    expect($this->placeholders->resolve($first, '10.100.0.6'))->toBeNull()
        ->and($this->placeholders->resolve($second, '10.100.0.6')?->is($account))->toBeTrue();
});

it('stops resolving once the sandbox is destroying or destroyed', function (string $state) {
    $thread = Thread::factory()->create(['sandbox_container' => 'yak-thread-1', 'sandbox_state' => ThreadSandboxState::Running]);
    $placeholder = $this->placeholders->issue($thread, ClaudeAccount::factory()->create(), '10.100.0.6');
    $thread->forceFill(['sandbox_state' => ThreadSandboxState::from($state)])->save();

    expect($this->placeholders->resolve($placeholder, '10.100.0.6'))->toBeNull();
})->with(['destroying', 'destroyed']);

it('stops resolving when the thread sits in another container', function () {
    $thread = Thread::factory()->create(['sandbox_container' => 'yak-thread-1']);
    $placeholder = $this->placeholders->issue($thread, ClaudeAccount::factory()->create(), '10.100.0.6');
    $thread->forceFill(['sandbox_container' => 'yak-thread-2'])->save();

    expect($this->placeholders->resolve($placeholder, '10.100.0.6'))->toBeNull();
});

it('expires a run placeholder after one turn wall clock plus ten minutes', function () {
    config(['yak.threads.turn_wall_clock_minutes' => 30]);
    $placeholder = $this->placeholders->issue('run:5', ClaudeAccount::factory()->create(), '10.100.0.6');

    $this->travel(39)->minutes();
    expect($this->placeholders->resolve($placeholder, '10.100.0.6'))->not->toBeNull();

    $this->travel(2)->minutes();
    expect($this->placeholders->resolve($placeholder, '10.100.0.6'))->toBeNull();
});
