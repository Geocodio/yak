<?php

use App\Models\ClaudeAccount;
use App\Models\User;
use App\Services\Push\AccountProblemPush;
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
