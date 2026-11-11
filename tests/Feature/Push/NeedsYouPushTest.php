<?php

use App\Enums\ThreadBlockReason;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\Push\PushEvent;
use App\Services\Push\PushRecipients;
use App\Services\Push\ThreadPush;
use Tests\Support\FakePushEndpoint;

beforeEach(function () {
    $this->push = FakePushEndpoint::install();
    $this->owner = User::factory()->create();
    $this->thread = Thread::factory()->create(['owner_user_id' => $this->owner->id]);
    $this->device = $this->push->subscription($this->owner);
});

it('pushes the owner when a thread hits a credential exposure or restore failure', function (ThreadBlockReason $reason) {
    $this->push->respondWith();

    $this->thread->update(['blocked_reason' => $reason, 'blocked_at' => now()]);

    $this->push->assertSentTo($this->device->endpoint);
})->with([ThreadBlockReason::CredentialExposure, ThreadBlockReason::RestoreFailed]);

it('stays silent for waiting states', function (ThreadBlockReason $reason) {
    $this->thread->update(['blocked_reason' => $reason, 'blocked_at' => now()]);

    $this->push->assertNothingSent();
})->with([ThreadBlockReason::WaitingForAccount, ThreadBlockReason::WaitingForSandbox]);

it('pushes only when the block reason changes', function () {
    $this->push->respondWith();
    $this->thread->update(['blocked_reason' => ThreadBlockReason::RestoreFailed]);
    $this->thread->update(['title' => 'Renamed']);

    expect($this->push->requests())->toHaveCount(1);
});

it('pushes the driver of a clarification question', function () {
    $this->push->respondWith();
    TaskRun::factory()->create(['thread_id' => $this->thread->id, 'driver_user_id' => $this->owner->id]);

    $sent = app(ThreadPush::class)->send(PushEvent::NeedsYou, $this->thread, app(PushRecipients::class)->forClarification($this->thread), 'Yak has a question for you.');

    expect($sent)->toBe(1);
    $this->push->assertSentTo($this->device->endpoint);
});

it('pushes a usage limit to the thread people and, once, to the account owner', function () {
    $accountOwner = User::factory()->create();
    $accountDevice = $this->push->subscription($accountOwner);
    $account = ClaudeAccount::factory()->create(['owner_user_id' => $accountOwner->id]);
    $this->thread->update(['claude_account_id' => $account->id]);
    $other = Thread::factory()->create(['owner_user_id' => $this->owner->id, 'claude_account_id' => $account->id]);
    $this->push->respondWith()->respondWith()->respondWith()->respondWith();

    $this->thread->update(['blocked_reason' => ThreadBlockReason::UsageLimit]);
    $other->update(['blocked_reason' => ThreadBlockReason::UsageLimit]);

    $toAccountOwner = array_filter($this->push->requests(), fn ($request) => (string) $request->getUri() === $accountDevice->endpoint);
    expect($toAccountOwner)->toHaveCount(1);
});
