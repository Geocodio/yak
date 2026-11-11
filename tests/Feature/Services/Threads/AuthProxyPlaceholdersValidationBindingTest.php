<?php

use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\Threads\AuthProxyPlaceholders;

beforeEach(function () {
    config(['yak.threads.auth_proxy_validation_cidrs' => ['172.16.0.0/12']]);
    $this->placeholders = new AuthProxyPlaceholders;
    $this->account = ClaudeAccount::factory()->create();
});

it('resolves a validation placeholder from inside the validation cidrs only', function () {
    $placeholder = $this->placeholders->issue('validation-7', $this->account, AuthProxyPlaceholders::VALIDATION_BINDING);

    expect($this->placeholders->resolve($placeholder, '172.18.0.5')?->is($this->account))->toBeTrue()
        ->and($this->placeholders->resolve($placeholder, '10.100.0.7'))->toBeNull()
        ->and($this->placeholders->resolve($placeholder, '127.0.0.1'))->toBeNull();
});

it('never resolves a thread placeholder issued with the sentinel', function () {
    $placeholder = $this->placeholders->issue(Thread::factory()->create(), $this->account, AuthProxyPlaceholders::VALIDATION_BINDING);

    expect($this->placeholders->resolve($placeholder, '172.18.0.5'))->toBeNull()
        ->and($this->placeholders->resolve($placeholder, '10.100.0.7'))->toBeNull();
});

it('still needs exact ip equality for a thread placeholder bound to an address', function () {
    $thread = Thread::factory()->create();
    $placeholder = $this->placeholders->issue($thread, $this->account, '10.100.0.7');

    expect($this->placeholders->resolve($placeholder, '10.100.0.7')?->is($this->account))->toBeTrue()
        ->and($this->placeholders->resolve($placeholder, '172.18.0.5'))->toBeNull();
});

it('resolves nothing for the sentinel when the cidr list is empty', function () {
    config(['yak.threads.auth_proxy_validation_cidrs' => []]);
    $placeholder = $this->placeholders->issue('validation-7', $this->account, AuthProxyPlaceholders::VALIDATION_BINDING);

    expect($this->placeholders->resolve($placeholder, '172.18.0.5'))->toBeNull();
});
