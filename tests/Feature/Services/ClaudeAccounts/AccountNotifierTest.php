<?php

use App\Mail\AccountProblemMail;
use App\Models\ClaudeAccount;
use App\Models\User;
use App\Services\ClaudeAccounts\AccountNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
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

it('emails the owner beside Slack with the account name and settings link', function () {
    $owner = slackUser('UOWNER');
    $account = ClaudeAccount::factory()->for($owner, 'owner')->invalid()->create(['name' => 'Work']);

    app(AccountNotifier::class)->invalid($account);

    Mail::assertSent(AccountProblemMail::class, 1);
    Mail::assertSent(AccountProblemMail::class, fn ($mail) => $mail->hasTo($owner->email)
        && str_contains($mail->notice, 'Work')
        && str_contains($mail->notice, route('settings.claude-accounts'))
        && ! str_contains($mail->notice, $account->secret));
    expect(sentTexts())->toHaveCount(1);
});

it('emails a user without a Slack id and one with direct messages off', function () {
    $owner = User::factory()->create(['slack_user_id' => null, 'direct_messages_enabled' => false]);
    $account = ClaudeAccount::factory()->for($owner, 'owner')->create();

    app(AccountNotifier::class)->invalid($account);

    Mail::assertSent(AccountProblemMail::class, fn ($mail) => $mail->hasTo($owner->email));
    Http::assertNothingSent();
});

it('emails every admin for a system account', function () {
    $admins = User::factory()->count(2)->create();
    User::factory()->create();
    config(['claude-accounts.admin_user_ids' => $admins->pluck('id')->all()]);

    app(AccountNotifier::class)->expired(ClaudeAccount::factory()->system()->create());

    Mail::assertSent(AccountProblemMail::class, 2);
});

it('still sends Slack when the mail fails', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));
    $owner = slackUser('UOWNER');
    $account = ClaudeAccount::factory()->for($owner, 'owner')->create();

    app(AccountNotifier::class)->invalid($account);

    expect(sentTexts())->toHaveCount(1);
});
