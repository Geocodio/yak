<?php

use App\Enums\ThreadBlockReason;
use App\Mail\AccountProblemMail;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config(['yak.channels.slack.bot_token' => 'xoxb-test']);
    Http::fake(['slack.com/*' => Http::response(['ok' => true])]);
    $this->owner = User::factory()->create(['slack_user_id' => 'UOWNER']);
});

function limitedThread(ClaudeAccount $account, ?User $driver = null): Thread
{
    $thread = Thread::factory()->create(['owner_user_id' => $account->owner_user_id, 'claude_account_id' => $account->id]);

    if ($driver !== null) {
        TaskRun::factory()->create(['thread_id' => $thread->id, 'driver_user_id' => $driver->id]);
    }

    return $thread;
}

function slackChannels(): array
{
    return Http::recorded()->map(fn ($pair) => $pair[0]->data()['channel'] ?? null)->filter()->values()->all();
}

it('tells the owner and the driver once however many threads the limit blocks', function () {
    $driver = User::factory()->create(['slack_user_id' => 'UDRIVER']);
    $account = ClaudeAccount::factory()->for($this->owner, 'owner')->usageLimited(now()->addHour()->toImmutable())->create();
    $first = limitedThread($account, $driver);
    $second = limitedThread($account, $driver);

    $first->update(['blocked_reason' => ThreadBlockReason::UsageLimit, 'blocked_at' => now()]);
    $second->update(['blocked_reason' => ThreadBlockReason::UsageLimit, 'blocked_at' => now()]);

    expect(slackChannels())->toBe(['UOWNER', 'UDRIVER']);
    Mail::assertSent(AccountProblemMail::class, 2);
    Mail::assertSent(AccountProblemMail::class, fn ($mail) => $mail->hasTo($driver->email) && str_contains($mail->notice, 'resets'));
});

it('tells a second driver once as well', function () {
    $account = ClaudeAccount::factory()->for($this->owner, 'owner')->usageLimited(now()->addHour()->toImmutable())->create();
    $other = User::factory()->create(['slack_user_id' => 'UOTHER']);
    limitedThread($account, $this->owner)->update(['blocked_reason' => ThreadBlockReason::UsageLimit, 'blocked_at' => now()]);

    limitedThread($account, $other)->update(['blocked_reason' => ThreadBlockReason::UsageLimit, 'blocked_at' => now()]);

    Mail::assertSent(AccountProblemMail::class, fn ($mail) => $mail->hasTo($other->email));
});

it('tells the admins when a system account hits its limit', function () {
    $admin = User::factory()->create();
    config(['claude-accounts.admin_user_ids' => [$admin->id]]);
    $account = ClaudeAccount::factory()->system()->usageLimited(now()->addHour()->toImmutable())->create();

    limitedThread($account)->update(['blocked_reason' => ThreadBlockReason::UsageLimit, 'blocked_at' => now()]);

    Mail::assertSent(AccountProblemMail::class, fn ($mail) => $mail->hasTo($admin->email));
});
