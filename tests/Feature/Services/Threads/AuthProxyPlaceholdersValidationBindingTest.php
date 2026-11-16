<?php

use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Services\Threads\AuthProxyPlaceholders;

beforeEach(function () {
    config(['yak.threads.auth_proxy_validation_address' => '172.30.0.2']);
    $this->placeholders = new AuthProxyPlaceholders;
    $this->account = ClaudeAccount::factory()->create();
});

it('resolves a validation placeholder from exactly the validation address', function () {
    $placeholder = $this->placeholders->issue('validation-7', $this->account, AuthProxyPlaceholders::VALIDATION_BINDING);

    expect($this->placeholders->resolve($placeholder, '172.30.0.2')?->is($this->account))->toBeTrue()
        ->and($this->placeholders->resolve($placeholder, '172.30.0.3'))->toBeNull()
        ->and($this->placeholders->resolve($placeholder, '172.18.0.5'))->toBeNull()
        ->and($this->placeholders->resolve($placeholder, '127.0.0.1'))->toBeNull();
});

it('never resolves a thread placeholder issued with the sentinel', function () {
    $placeholder = $this->placeholders->issue(Thread::factory()->create(), $this->account, AuthProxyPlaceholders::VALIDATION_BINDING);

    expect($this->placeholders->resolve($placeholder, '172.30.0.2'))->toBeNull()
        ->and($this->placeholders->resolve($placeholder, '172.18.0.5'))->toBeNull();
});

it('still needs exact ip equality for a thread placeholder bound to an address', function () {
    $thread = Thread::factory()->create();
    $placeholder = $this->placeholders->issue($thread, $this->account, '10.100.0.7');

    expect($this->placeholders->resolve($placeholder, '10.100.0.7')?->is($this->account))->toBeTrue()
        ->and($this->placeholders->resolve($placeholder, '172.30.0.2'))->toBeNull();
});

it('resolves nothing for the sentinel when the address is unset or empty', function (?string $address) {
    config(['yak.threads.auth_proxy_validation_address' => $address]);
    $placeholder = $this->placeholders->issue('validation-7', $this->account, AuthProxyPlaceholders::VALIDATION_BINDING);

    expect($this->placeholders->resolve($placeholder, '172.30.0.2'))->toBeNull();
})->with([null, '']);

it('expires a validation placeholder after five minutes', function () {
    $placeholder = $this->placeholders->issue('validation-7', $this->account, AuthProxyPlaceholders::VALIDATION_BINDING);

    $this->travel(299)->seconds();
    expect($this->placeholders->resolve($placeholder, '172.30.0.2')?->is($this->account))->toBeTrue();

    $this->travel(2)->seconds();
    expect($this->placeholders->resolve($placeholder, '172.30.0.2'))->toBeNull();
});
