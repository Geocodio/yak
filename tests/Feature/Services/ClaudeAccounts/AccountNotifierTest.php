<?php

use App\Models\ClaudeAccount;
use App\Models\User;
use App\Services\ClaudeAccounts\AccountNotifier;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['yak.channels.slack.bot_token' => 'xoxb-test']);
    Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
});

function slackUser(string $slackId = 'U1'): User
{
    return User::factory()->create(['slack_user_id' => $slackId]);
}

function sentTexts(): array
{
    return Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();
}

it('tells the owner an account went invalid without leaking details', function () {
    $owner = slackUser('UOWNER');
    $account = ClaudeAccount::factory()->for($owner, 'owner')->invalid()->create(['name' => 'Work']);

    app(AccountNotifier::class)->invalid($account);

    $sent = sentTexts();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['channel'])->toBe('UOWNER')
        ->and($sent[0]['text'])->toContain('Work')->toContain(route('settings.claude-accounts'))
        ->not->toContain($account->secret)
        ->not->toContain('setup_token')
        ->not->toContain('Authentication failed');
});

it('sends system account problems to the admins', function () {
    $admin = slackUser('UADMIN');
    slackUser('UOTHER');
    config(['claude-accounts.admin_user_ids' => [$admin->id]]);
    $account = ClaudeAccount::factory()->system()->create(['name' => 'Yak']);

    app(AccountNotifier::class)->expiringSoon($account);

    expect(collect(sentTexts())->pluck('channel')->all())->toBe(['UADMIN']);
});

it('sends a usage limit to the owner and the driver once each', function () {
    $owner = slackUser('UOWNER');
    $driver = slackUser('UDRIVER');
    $account = ClaudeAccount::factory()->for($owner, 'owner')->create(['name' => 'Work']);

    app(AccountNotifier::class)->usageLimit($account, now()->addHour()->toImmutable(), $driver);
    app(AccountNotifier::class)->usageLimit($account, null, $owner);

    expect(collect(sentTexts())->pluck('channel')->all())->toBe(['UOWNER', 'UDRIVER', 'UOWNER']);
});

it('skips users with direct messages turned off', function () {
    $owner = slackUser();
    $owner->forceFill(['direct_messages_enabled' => false])->save();
    $account = ClaudeAccount::factory()->for($owner, 'owner')->create();

    app(AccountNotifier::class)->invalid($account);

    Http::assertNothingSent();
});
