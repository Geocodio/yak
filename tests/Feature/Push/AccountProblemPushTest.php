<?php

use App\Enums\ClaudeAccountStatus;
use App\Models\ClaudeAccount;
use App\Models\User;
use App\Services\Push\AccountProblemPush;
use App\Services\Push\PushPayloadFactory;
use Tests\Support\FakePushEndpoint;

it('pushes to the account owner only, ignoring event toggles', function () {
    $push = FakePushEndpoint::install();
    $owner = User::factory()->create(['push_event_preferences' => ['needs_you' => false, 'turn_finished' => false, 'stack_usage' => false, 'pull_request' => false]]);
    $other = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $owner->id]);
    $device = $push->subscription($owner);
    $push->subscription($other);
    $push->respondWith();

    app(AccountProblemPush::class)->send($account, 'Needs sign-in');

    expect($push->requests())->toHaveCount(1);
    $push->assertSentTo($device->endpoint);
});

it('is a no-op for a system account', function () {
    $push = FakePushEndpoint::install();
    $push->subscription(User::factory()->create());

    app(AccountProblemPush::class)->send(ClaudeAccount::factory()->system()->create(), 'Needs sign-in');

    $push->assertNothingSent();
});

it('pushes once when an account turns invalid, and again after it recovers', function () {
    $push = FakePushEndpoint::install();
    $owner = User::factory()->create();
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $owner->id]);
    $push->subscription($owner);
    $push->respondWith()->respondWith();

    $account->markInvalid('bad token');
    $account->markInvalid('bad token');

    expect($push->requests())->toHaveCount(1);

    $account->forceFill(['status' => ClaudeAccountStatus::Active])->save();
    $account->markInvalid('bad token');

    expect($push->requests())->toHaveCount(2);
});

it('names the account and no thread in the payload', function () {
    $account = ClaudeAccount::factory()->create(['name' => 'Work account']);
    $payload = app(PushPayloadFactory::class)->forAccount($account, "{$account->name} stopped working. Open Settings to fix it.");

    expect(json_encode($payload))->toContain('Work account')->not->toContain('thread');
});
