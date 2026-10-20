<?php

use App\Models\User;
use App\Services\CoOwner\SlackUserResolver;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
});

it('finds a user by stored Slack id without calling Slack', function () {
    Http::fake();
    $user = User::factory()->create(['slack_user_id' => 'U1']);

    expect(app(SlackUserResolver::class)->userFor('U1')?->is($user))->toBeTrue();
    Http::assertNothingSent();
});

it('finds a user by email through users.info and stores the Slack id', function () {
    Http::fake(['https://slack.com/api/users.info*' => Http::response(['ok' => true, 'user' => ['profile' => ['email' => 'Dev@Example.com']]])]);
    $user = User::factory()->create(['email' => 'dev@example.com']);

    expect(app(SlackUserResolver::class)->userFor('U2')?->is($user))->toBeTrue()
        ->and($user->fresh()->slack_user_id)->toBe('U2');
});

it('returns null when Slack does not know the user', function () {
    Http::fake(['https://slack.com/api/users.info*' => Http::response(['ok' => false, 'error' => 'user_not_found'])]);

    expect(app(SlackUserResolver::class)->userFor('U3'))->toBeNull();
});
