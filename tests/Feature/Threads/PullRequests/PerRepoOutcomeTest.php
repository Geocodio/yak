<?php

use App\Actions\RecordPullRequestOutcome;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\Threads\ThreadSandbox;

const API_PR = 'https://github.com/acme/api/pull/7';
const WEB_PR = 'https://github.com/acme/web/pull/7';

function twoRepoThread(): Thread
{
    $thread = Thread::factory()->create();
    ThreadRepository::factory()->for($thread)->create(['repo' => 'acme/api', 'pull_request_url' => API_PR, 'pull_request_number' => 7, 'pull_request_state' => 'open']);
    ThreadRepository::factory()->for($thread)->changes()->create(['repo' => 'acme/web', 'pull_request_url' => WEB_PR, 'pull_request_number' => 7, 'pull_request_state' => 'open']);
    YakTask::factory()->create(['thread_id' => $thread->id, 'pr_url' => API_PR, 'pr_number' => 7]);

    return $thread;
}

it('keeps the thread open and the sandbox alive when one of two pull requests merges', function () {
    $thread = twoRepoThread();
    $this->mock(ThreadSandbox::class)->shouldNotReceive('destroy');

    app(RecordPullRequestOutcome::class)->record(WEB_PR, merged: true);

    expect($thread->refresh()->status)->toBe(ThreadStatus::Open)
        ->and(ThreadRepository::where('pull_request_url', WEB_PR)->value('pull_request_state'))->toBe('merged')
        ->and(ThreadRepository::where('pull_request_url', API_PR)->value('pull_request_state'))->toBe('open');
});

it('closes with pr_merged exactly once when the last pull request ends', function () {
    $thread = twoRepoThread();
    $this->mock(ThreadSandbox::class)->shouldReceive('destroy')->once();

    app(RecordPullRequestOutcome::class)->record(WEB_PR, merged: true);
    app(RecordPullRequestOutcome::class)->record(API_PR, merged: true);
    app(RecordPullRequestOutcome::class)->record(API_PR, merged: true);

    expect($thread->refresh()->status)->toBe(ThreadStatus::Closed)->and($thread->close_reason)->toBe('pr_merged');
});

it('uses pr_merged for a mix of merged and closed', function () {
    $thread = twoRepoThread();

    app(RecordPullRequestOutcome::class)->record(WEB_PR, merged: false);
    app(RecordPullRequestOutcome::class)->record(API_PR, merged: true);

    expect($thread->refresh()->close_reason)->toBe('pr_merged');
});

it('uses pr_closed when every pull request closed unmerged', function () {
    $thread = twoRepoThread();

    app(RecordPullRequestOutcome::class)->record(WEB_PR, merged: false);
    app(RecordPullRequestOutcome::class)->record(API_PR, merged: false);

    expect($thread->refresh()->close_reason)->toBe('pr_closed');
});

it('ignores context rows when deciding to close', function () {
    $thread = twoRepoThread();
    ThreadRepository::factory()->for($thread)->context()->create(['repo' => 'acme/docs']);

    app(RecordPullRequestOutcome::class)->record(WEB_PR, merged: true);
    app(RecordPullRequestOutcome::class)->record(API_PR, merged: true);

    expect($thread->refresh()->status)->toBe(ThreadStatus::Closed);
});

it('closes a single pull request thread without a repo row as before', function () {
    $thread = Thread::factory()->create();
    YakTask::factory()->create(['thread_id' => $thread->id, 'pr_url' => API_PR, 'pr_number' => 7]);

    app(RecordPullRequestOutcome::class)->record(API_PR, merged: true);

    expect($thread->refresh()->close_reason)->toBe('pr_merged');
});

it('still stamps the primary task chain', function () {
    twoRepoThread();

    app(RecordPullRequestOutcome::class)->record(API_PR, merged: true);

    expect(YakTask::where('pr_url', API_PR)->first()->pr_merged_at)->not->toBeNull();
});

it('records an outcome for a pull request that has a row and no task', function () {
    twoRepoThread();

    expect(app(RecordPullRequestOutcome::class)->record(WEB_PR, merged: true))->toBeTrue();
});
