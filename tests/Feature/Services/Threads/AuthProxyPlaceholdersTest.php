<?php

use App\Enums\ClaudeAccountKind;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\Threads\AuthProxyPlaceholders;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->account = ClaudeAccount::factory()->create(['secret' => 'sk-ant-secret-value', 'kind' => ClaudeAccountKind::ApiKey]);
    $this->thread = Thread::factory()->create(['sandbox_container' => 'yak-thread-1']);
    $this->placeholders = app(AuthProxyPlaceholders::class);
});

it('issues a long random placeholder that resolves to the credential and the thread container', function () {
    $placeholder = $this->placeholders->issueForThread($this->thread, $this->account);

    expect($placeholder)->toStartWith('yakph-')->and(strlen($placeholder))->toBe(6 + 64)
        ->and($this->placeholders->resolve($placeholder))->toBe(['type' => 'api_key', 'value' => 'sk-ant-secret-value', 'container' => 'yak-thread-1']);
});

it('never stores the secret in the cache', function () {
    $placeholder = $this->placeholders->issueForThread($this->thread, $this->account);
    $stored = Cache::store(config('yak.credential_reference_store'))->get('auth-proxy-placeholder:' . hash('sha256', $placeholder));

    expect(json_encode($stored))->not->toContain('sk-ant-secret-value');
});

it('revokes the previous placeholder when a thread gets a new one', function () {
    $first = $this->placeholders->issueForThread($this->thread, $this->account);
    $second = $this->placeholders->issueForThread($this->thread, $this->account);

    expect($this->placeholders->resolve($first))->toBeNull()
        ->and($this->placeholders->resolve($second))->not->toBeNull();
});

it('revokes a thread placeholder when its runner ends', function () {
    $placeholder = $this->placeholders->issueForThread($this->thread, $this->account);

    $this->placeholders->revokeForThread($this->thread);

    expect($this->placeholders->resolve($placeholder))->toBeNull();
});

it('stops resolving once the thread is closed or its account is gone', function () {
    $placeholder = $this->placeholders->issueForThread($this->thread, $this->account);

    $this->thread->forceFill(['status' => 'closed'])->save();
    expect($this->placeholders->resolve($placeholder))->toBeNull();

    $this->thread->forceFill(['status' => 'open'])->save();
    $this->account->delete();
    expect($this->placeholders->resolve($placeholder))->toBeNull();
});

it('binds a one-shot placeholder to its container and revokes it', function () {
    $placeholder = $this->placeholders->issueForContainer('yak-task-9', $this->account);

    expect($this->placeholders->resolve($placeholder)['container'])->toBe('yak-task-9');

    $this->placeholders->revoke($placeholder);
    expect($this->placeholders->resolve($placeholder))->toBeNull();
});

it('keeps a validation secret encrypted, bound to the host and short lived', function () {
    $placeholder = $this->placeholders->issueForValidation(ClaudeAccountKind::SetupToken, 'pasted-token');
    $stored = Cache::store(config('yak.credential_reference_store'))->get('auth-proxy-placeholder:' . hash('sha256', $placeholder));

    expect(json_encode($stored))->not->toContain('pasted-token')
        ->and($this->placeholders->resolve($placeholder))->toBe(['type' => 'oauth_token', 'value' => 'pasted-token', 'host' => true]);

    $this->travel(301)->seconds();

    expect($this->placeholders->resolve($placeholder))->toBeNull();
});

it('refuses a placeholder it never issued', function () {
    expect($this->placeholders->resolve('yakph-nope'))->toBeNull()
        ->and($this->placeholders->resolve('sk-ant-real-looking'))->toBeNull();
});

it('keeps the placeholder store out of redis', function () {
    expect(config('yak.credential_reference_store'))->not->toBe('redis');
});
