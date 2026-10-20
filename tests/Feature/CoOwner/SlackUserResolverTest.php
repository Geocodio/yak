<?php

use App\Channels\Slack\SenderPolicy;
use App\Models\User;
use App\Services\CoOwner\SlackUserResolver;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    app()->forgetInstance(SenderPolicy::class);
    config()->set('yak.channels.slack.bot_token', 'xoxb-test');
});

it('finds a user by stored Slack id without calling Slack', function () {
    Http::fake();
    $user = User::factory()->create(['slack_user_id' => 'U1']);

    expect(app(SlackUserResolver::class)->userFor('U1')?->is($user))->toBeTrue();
    Http::assertNothingSent();
});

function fakeSlackMember(array $overrides = []): void
{
    Http::fake([
        'https://slack.com/api/auth.test*' => Http::response(['ok' => true, 'team_id' => 'T1']),
        'https://slack.com/api/users.info*' => Http::response(['ok' => true, 'user' => $overrides + ['team_id' => 'T1', 'profile' => ['email' => 'Dev@Example.com']]]),
    ]);
}

it('finds a user by email through users.info and stores the Slack id', function () {
    fakeSlackMember();
    $user = User::factory()->create(['email' => 'dev@example.com']);

    expect(app(SlackUserResolver::class)->userFor('U2')?->is($user))->toBeTrue()
        ->and($user->fresh()->slack_user_id)->toBe('U2');
});

it('returns null when Slack does not know the user', function () {
    Http::fake(['https://slack.com/api/users.info*' => Http::response(['ok' => false, 'error' => 'user_not_found'])]);

    expect(app(SlackUserResolver::class)->userFor('U3'))->toBeNull();
});

it('does not link guests, strangers, deactivated users or other workspaces', function (array $overrides) {
    fakeSlackMember($overrides);
    $user = User::factory()->create(['email' => 'dev@example.com']);

    expect(app(SlackUserResolver::class)->userFor('U4'))->toBeNull()
        ->and($user->fresh()->slack_user_id)->toBeNull();
})->with([
    'guest' => [['is_restricted' => true]],
    'single-channel guest' => [['is_ultra_restricted' => true]],
    'stranger' => [['is_stranger' => true]],
    'deleted' => [['deleted' => true]],
    'foreign team' => [['team_id' => 'T9']],
]);
