<?php

use App\DataTransferObjects\TurnTrackerChange;
use App\Mail\AccountProblemMail;
use App\Models\ClaudeAccount;
use App\Models\User;
use App\Services\ClaudeAccounts\AccountFailure;
use App\Services\ClaudeAccounts\AccountFailureKind;
use App\Services\ClaudeAccounts\AccountNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';

beforeEach(function () {
    Mail::fake();
    config(['yak.channels.slack.bot_token' => 'xoxb-test']);
    Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
});

it('notifies once per invalid episode', function () {
    $owner = User::factory()->create(['slack_user_id' => 'UOWNER']);
    $account = ClaudeAccount::factory()->for($owner, 'owner')->create();

    $account->markInvalid('bad token');
    $account->markInvalid('bad token');

    Mail::assertSent(AccountProblemMail::class, 1);
    Http::assertSentCount(1);
});

it('tells the admins when a system account is rejected', function () {
    $admin = User::factory()->create();
    config(['claude-accounts.admin_user_ids' => [$admin->id]]);

    ClaudeAccount::factory()->system()->create()->markInvalid('bad token');

    Mail::assertSent(AccountProblemMail::class, fn ($mail) => $mail->hasTo($admin->email));
});

it('does not break the turn when the notifier fails', function () {
    $this->mock(AccountNotifier::class)->shouldReceive('invalid')->andThrow(new RuntimeException('boom'));
    $account = ClaudeAccount::factory()->create();

    $account->markInvalid('bad token');

    expect($account->fresh()->last_error)->toBe('bad token');
});

it('notifies the owner when the driver sees authentication fail', function () {
    $owner = User::factory()->create();
    bindTrackerChanges([new TurnTrackerChange(ended: true, failure: new AccountFailure(AccountFailureKind::AuthFailed))]);
    [$thread] = runningTurnFor();
    $thread->claudeAccount->forceFill(['owner_user_id' => $owner->id])->save();

    pushRunnerLine($thread->refresh(), 1, ['type' => 'assistant']);

    Mail::assertSent(AccountProblemMail::class, fn ($mail) => $mail->hasTo($owner->email));
});
