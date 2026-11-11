<?php

use App\Enums\ClaudeAccountStatus;
use App\Models\ClaudeAccount;
use App\Services\ClaudeAccounts\AccountNotifier;

beforeEach(function () {
    $this->notifier = Mockery::mock(AccountNotifier::class);
    app()->instance(AccountNotifier::class, $this->notifier);
});

it('warns once per account inside the warning window', function () {
    $account = ClaudeAccount::factory()->expiring(10)->create();
    ClaudeAccount::factory()->expiring(40)->create();
    $this->notifier->shouldReceive('expiringSoon')->once()->withArgs(fn ($a) => $a->is($account));

    $this->artisan('yak:claude-accounts-expiry')->assertSuccessful();
    $this->artisan('yak:claude-accounts-expiry')->assertSuccessful();

    expect($account->fresh()->last_expiry_warning_at)->not->toBeNull();
});

it('expires an account once its date has passed', function () {
    $account = ClaudeAccount::factory()->create(['expires_at' => now()->addDay()]);
    $this->notifier->shouldReceive('expiringSoon')->once();
    $this->notifier->shouldReceive('expired')->once();

    $this->artisan('yak:claude-accounts-expiry');
    $this->travel(2)->days();
    $this->artisan('yak:claude-accounts-expiry');

    expect($account->fresh()->status)->toBe(ClaudeAccountStatus::Expired);
});

it('skips api keys without an expiry', function () {
    $account = ClaudeAccount::factory()->apiKey()->create(['expires_at' => null]);
    $this->notifier->shouldNotReceive('expiringSoon');
    $this->notifier->shouldNotReceive('expired');

    $this->artisan('yak:claude-accounts-expiry');
    $this->travel(400)->days();
    $this->artisan('yak:claude-accounts-expiry');

    expect($account->fresh()->status)->toBe(ClaudeAccountStatus::Active);
});
