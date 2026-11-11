<?php

use App\Actions\RecordPullRequestOutcome;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\ThreadCloser;

function threadWithTaskIdle(int $idleDays, array $task = [], array $thread = []): Thread
{
    $record = Thread::factory()->create(['last_activity_at' => now()->subDays($idleDays), ...$thread]);
    YakTask::factory()->create(['thread_id' => $record->id, 'status' => TaskStatus::Success, 'pr_url' => null, ...$task]);

    return $record;
}

it('closes the thread when its PR merges', function () {
    $thread = threadWithTaskIdle(0, ['pr_url' => 'https://github.com/acme/web/pull/7', 'pr_number' => 7]);

    app(RecordPullRequestOutcome::class)->record('https://github.com/acme/web/pull/7', merged: true);

    expect($thread->refresh()->status)->toBe(ThreadStatus::Closed)->and($thread->close_reason)->toBe('pr_merged');
});

it('closes the thread when its PR is closed unmerged', function () {
    $thread = threadWithTaskIdle(0, ['pr_url' => 'https://github.com/acme/web/pull/8', 'pr_number' => 8]);

    app(RecordPullRequestOutcome::class)->record('https://github.com/acme/web/pull/8', merged: false);

    expect($thread->refresh()->close_reason)->toBe('pr_closed');
});

it('closes a thread idle past the destroy window', function () {
    $days = (int) config('yak.threads.destroy_days');
    $idle = threadWithTaskIdle($days + 1);
    $recent = threadWithTaskIdle($days - 1);

    $this->artisan('yak:close-idle-threads')->assertSuccessful();

    expect($idle->refresh()->status)->toBe(ThreadStatus::Closed)
        ->and($idle->close_reason)->toBe('idle')
        ->and($recent->refresh()->status)->toBe(ThreadStatus::Open);
});

it('leaves device-owned, running and open-PR threads open', function () {
    $days = (int) config('yak.threads.destroy_days') + 1;
    $device = threadWithTaskIdle($days, [], ['owner_side' => ThreadOwnerSide::Device]);
    $running = threadWithTaskIdle($days, ['status' => TaskStatus::Running]);
    $openPr = threadWithTaskIdle($days, ['pr_url' => 'https://github.com/acme/web/pull/9', 'pr_number' => 9]);

    $this->artisan('yak:close-idle-threads')->assertSuccessful();

    expect($device->refresh()->status)->toBe(ThreadStatus::Open)
        ->and($running->refresh()->status)->toBe(ThreadStatus::Open)
        ->and($openPr->refresh()->status)->toBe(ThreadStatus::Open);
});

beforeEach(fn () => config()->set('yak.threads.terminal_close_hours', 24));

function terminalThread(int $finishedHoursAgo, array $task = [], array $thread = []): Thread
{
    $record = Thread::factory()->create(['last_activity_at' => now(), ...$thread]);
    YakTask::factory()->create([
        'thread_id' => $record->id,
        'status' => TaskStatus::Success,
        'pr_url' => null,
        'branch_name' => null,
        'completed_at' => now()->subHours($finishedHoursAgo),
        ...$task,
    ]);

    return $record;
}

it('closes a research thread whose task finished more than the window ago', function () {
    $thread = terminalThread(25, ['mode' => TaskMode::Research]);

    $this->artisan('yak:close-idle-threads')->assertSuccessful();

    expect($thread->refresh()->status)->toBe(ThreadStatus::Closed)->and($thread->close_reason)->toBe('terminal');
});

it('closes a thread whose only task failed without a branch after the window', function () {
    $thread = terminalThread(25, ['status' => TaskStatus::Failed]);

    $this->artisan('yak:close-idle-threads')->assertSuccessful();

    expect($thread->refresh()->close_reason)->toBe('terminal');
});

it('keeps a thread whose terminal task finished inside the window', function () {
    $thread = terminalThread(1);

    $this->artisan('yak:close-idle-threads')->assertSuccessful();

    expect($thread->refresh()->status)->toBe(ThreadStatus::Open);
});

it('keeps a thread whose failed task has a branch', function () {
    $thread = terminalThread(48, ['status' => TaskStatus::Failed, 'branch_name' => 'yak/retry-me']);

    $this->artisan('yak:close-idle-threads')->assertSuccessful();

    expect($thread->refresh()->status)->toBe(ThreadStatus::Open);
});

it('keeps a thread with an active task, an open PR or a device owner past the terminal window', function () {
    $active = terminalThread(48, ['status' => TaskStatus::Running]);
    $openPr = terminalThread(48, ['pr_url' => 'https://github.com/acme/web/pull/10', 'pr_number' => 10]);
    $device = terminalThread(48, [], ['owner_side' => ThreadOwnerSide::Device]);

    $this->artisan('yak:close-idle-threads')->assertSuccessful();

    expect($active->refresh()->status)->toBe(ThreadStatus::Open)
        ->and($openPr->refresh()->status)->toBe(ThreadStatus::Open)
        ->and($device->refresh()->status)->toBe(ThreadStatus::Open);
});

it('closes terminal threads through ThreadCloser', function () {
    $closer = Mockery::mock(ThreadCloser::class);
    app()->instance(ThreadCloser::class, $closer);
    $thread = terminalThread(48);
    $closer->shouldReceive('close')->once()->withArgs(fn (Thread $closed, string $reason) => $closed->is($thread) && $reason === 'terminal');

    $this->artisan('yak:close-idle-threads')->assertSuccessful();
});
