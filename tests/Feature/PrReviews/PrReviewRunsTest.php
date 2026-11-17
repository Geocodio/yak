<?php

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\TaskStatus;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\TaskLog;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    config()->set('yak.channels.github.installation_id', 12345);
});

test('the runs of a pull request come newest first with their status, error and rights', function () {
    $oldest = seedReviewRun(['status' => TaskStatus::Success, 'created_at' => now()->subDays(2), 'started_at' => now()->subDays(2), 'cost_usd' => 0.57, 'duration_ms' => 120000, 'num_turns' => 21]);
    PrReview::factory()->create(['yak_task_id' => $oldest->id, 'repo' => 'geocodio/api', 'pr_number' => 50, 'commit_sha_reviewed' => 'c80be55aaaa']);
    $failed = seedReviewRun(['status' => TaskStatus::Failed, 'created_at' => now()->subDay(), 'started_at' => now()->subDay(), 'error_log' => 'GitHub returned 404 for the diff', 'cost_usd' => 0.02, 'duration_ms' => 20000, 'num_turns' => 3]);
    $newest = seedReviewRun(['status' => TaskStatus::Success, 'created_at' => now(), 'started_at' => now(), 'cost_usd' => 0.64, 'duration_ms' => 125000, 'num_turns' => 24]);
    $review = PrReview::factory()->incremental()->create(['yak_task_id' => $newest->id, 'repo' => 'geocodio/api', 'pr_number' => 50, 'commit_sha_reviewed' => '9f3c2abbbbb', 'summary' => 'The **move** is consistent.']);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'severity' => 'should_fix']);
    PrReviewComment::factory()->create(['pr_review_id' => $review->id, 'severity' => 'consider']);

    $this->get(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('PrReviews/ForPr')
            ->has('runs', 3)
            ->where('runs.0.id', $newest->public_id)
            ->where('runs.0.status', 'reviewed')
            ->where('runs.0.scope', 'incremental')
            ->where('runs.0.commit', '9f3c2ab')
            ->where('runs.0.stepCount', 24)
            ->where('runs.0.durationSeconds', 125)
            ->where('runs.0.costUsd', 0.64)
            ->where('runs.0.counts', ['mustFix' => 0, 'shouldFix' => 1, 'consider' => 1])
            ->where('runs.0.verdict', fn ($html) => str_contains($html, '<strong>move</strong>'))
            ->has('runs.0.findings.comments', 2)
            ->where('runs.0.canRerun', true)
            ->where('runs.0.isLive', false)
            ->where('runs.0.logUrl', route('pr-reviews.for-pr.runs.log', ['repoSlug' => 'geocodio/api', 'prNumber' => 50, 'task' => $newest->public_id]))
            ->where('runs.1.id', $failed->public_id)
            ->where('runs.1.status', 'failed')
            ->where('runs.1.error', 'GitHub returned 404 for the diff')
            ->where('runs.1.findings', null)
            ->where('runs.2.id', $oldest->public_id)
            ->where('pullRequest.runCount', 3)
            ->where('pullRequest.failedCount', 1)
            ->where('pullRequest.totalCostUsd', 1.23)
            ->where('pullRequest.author', 'imliam')
            ->where('pullRequest.branch', 'feature/billing'));
});

test('a queued or reviewing run is live and shows its latest activity', function () {
    seedReviewRun(['status' => TaskStatus::Pending]);
    $running = seedReviewRun(['status' => TaskStatus::Running, 'started_at' => now()]);
    TaskLog::create(['yak_task_id' => $running->id, 'level' => 'info', 'message' => 'Reading the diff']);

    $this->get(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 2)
            ->where('runs.0.isLive', true)
            ->where('runs.0.canRerun', false)
            ->where('runs.1.isLive', true)
            ->where('runs.1.status', fn ($status) => in_array($status, ['queued', 'reviewing']))
            ->where('runs.0.latestActivity', fn ($line) => $line === null || str_contains((string) $line, 'Reading the diff')));
});

test('runs of another pull request or repository are left out', function () {
    seedReviewRun([], 51);
    seedReviewRun([], 50, 'geocodio/other');

    $this->get(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertInertia(fn (Assert $page) => $page->has('runs', 0));
});

test('the related thread is named only to a viewer who can see it', function () {
    $visible = Thread::factory()->create(['title' => 'Bill owned prices', 'source_url' => 'https://linear.app/x/issue/ENG-1', 'source_reference' => 'ENG-1', 'source' => 'linear']);
    YakTask::factory()->success()->create(['thread_id' => $visible->id, 'repo' => 'geocodio/api', 'pr_url' => 'https://github.com/geocodio/api/pull/50']);
    seedReviewRun();

    $this->get(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pullRequest.thread.title', 'Bill owned prices')
            ->where('pullRequest.thread.id', $visible->public_id)
            ->where('pullRequest.source.url', 'https://linear.app/x/issue/ENG-1'));

    $visible->forceFill(['visibility' => 'private', 'owner_user_id' => User::factory()->create()->id])->save();

    $this->get(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertInertia(fn (Assert $page) => $page->where('pullRequest.thread', null)->where('pullRequest.source', null));
});

test('a pull request with no runs shows an empty list', function () {
    $this->get(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 999]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('runs', 0)->where('pullRequest.runCount', 0));
});

test('the run log answers with the entries of the run', function () {
    $task = seedReviewRun(['status' => TaskStatus::Success]);
    TaskLog::create(['yak_task_id' => $task->id, 'level' => 'info', 'message' => 'Reading the diff']);

    $this->getJson(route('pr-reviews.for-pr.runs.log', ['repoSlug' => 'geocodio/api', 'prNumber' => 50, 'task' => $task->public_id]))
        ->assertOk()
        ->assertJsonPath('entries.0.text', 'Reading the diff')
        ->assertJsonPath('nextCursor', null);
});

test('a run of another pull request answers 404 on the log and the re-run', function () {
    $task = seedReviewRun([], 51);

    $this->getJson(route('pr-reviews.for-pr.runs.log', ['repoSlug' => 'geocodio/api', 'prNumber' => 50, 'task' => $task->public_id]))->assertNotFound();
    $this->post(route('pr-reviews.for-pr.runs.rerun', ['repoSlug' => 'geocodio/api', 'prNumber' => 50, 'task' => $task->public_id]))->assertNotFound();
});

test('re-running a run starts that review again', function () {
    Queue::fake();
    $task = seedReviewRun(['status' => TaskStatus::Failed, 'error_log' => 'boom']);
    $github = mock(GitHubAppService::class);
    $github->shouldReceive('getPullRequest')->andReturn(['head' => ['sha' => 'abc', 'ref' => 'feature/billing'], 'base' => ['sha' => 'b', 'ref' => 'main'], 'title' => 'Bill', 'body' => '', 'user' => ['login' => 'imliam'], 'number' => 50, 'html_url' => 'https://github.com/geocodio/api/pull/50']);
    app()->instance(GitHubAppService::class, $github);

    $this->post(route('pr-reviews.for-pr.runs.rerun', ['repoSlug' => 'geocodio/api', 'prNumber' => 50, 'task' => $task->public_id]))
        ->assertRedirect(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertSessionHas('success');

    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
});

test('a refused re-run shows its message', function () {
    $task = seedReviewRun(['status' => TaskStatus::Running]);

    $this->post(route('pr-reviews.for-pr.runs.rerun', ['repoSlug' => 'geocodio/api', 'prNumber' => 50, 'task' => $task->public_id]))
        ->assertRedirect(route('pr-reviews.for-pr', ['repoSlug' => 'geocodio/api', 'prNumber' => 50]))
        ->assertSessionHas('error', 'A review is already queued for this PR.');
});

test('a guest cannot read or re-run a run', function () {
    $task = seedReviewRun();
    auth()->logout();

    $this->getJson(route('pr-reviews.for-pr.runs.log', ['repoSlug' => 'geocodio/api', 'prNumber' => 50, 'task' => $task->public_id]))->assertUnauthorized();
    $this->post(route('pr-reviews.for-pr.runs.rerun', ['repoSlug' => 'geocodio/api', 'prNumber' => 50, 'task' => $task->public_id]))->assertRedirect(route('login'));
});

test('a run of a private thread of someone else cannot be re-run or read', function () {
    $thread = Thread::factory()->private()->create();
    $task = seedReviewRun(['thread_id' => $thread->id]);

    $this->getJson(route('pr-reviews.for-pr.runs.log', ['repoSlug' => 'geocodio/api', 'prNumber' => 50, 'task' => $task->public_id]))->assertNotFound();
    $this->post(route('pr-reviews.for-pr.runs.rerun', ['repoSlug' => 'geocodio/api', 'prNumber' => 50, 'task' => $task->public_id]))->assertNotFound();
});
