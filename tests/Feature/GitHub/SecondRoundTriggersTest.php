<?php

use App\Actions\StartSecondRound;
use App\Channels\GitHub\AppService;
use App\Enums\TaskMode;
use App\Jobs\QuietPeriodReviewJob;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const ROUND_PR_URL = 'https://github.com/acme/app/pull/7';

function roundPullRequest(string $headSha = 'sha-new'): array
{
    return [
        'number' => 7,
        'state' => 'open',
        'html_url' => ROUND_PR_URL,
        'title' => 'Change',
        'user' => ['login' => 'dev'],
        'head' => ['sha' => $headSha, 'ref' => 'feature'],
        'base' => ['sha' => 'base-sha', 'ref' => 'main'],
    ];
}

beforeEach(function () {
    configureFakeGitHubApp();
    config()->set('yak.channels.github.webhook_secret', 'secret');
    config()->set('yak.channels.github.installation_id', 12345);
    (new ChannelServiceProvider(app()))->boot();
    Queue::fake();
    $this->repository = Repository::factory()->create([
        'slug' => 'app',
        'github_full_name' => 'acme/app',
        'github_repo_id' => 555,
        'approval_level' => 2,
        'pr_review_enabled' => true,
    ]);
    $this->review = PrReview::factory()->create([
        'yak_task_id' => YakTask::factory()->create(['mode' => TaskMode::Review]),
        'repo' => 'acme/app',
        'pr_number' => 7,
        'pr_url' => ROUND_PR_URL,
        'commit_sha_reviewed' => 'sha-old',
        'risk_assessment' => ['event' => 'COMMENT', 'findings_count' => 1],
    ]);
    PrReviewComment::factory()->create(['pr_review_id' => $this->review->id, 'github_comment_id' => 900]);
});

function fakeRoundGitHub(array $threads = [], string $headSha = 'sha-new', array $checkRuns = []): void
{
    Http::fake(gitHubTokenFake() + [
        'api.github.com/graphql' => Http::response(['data' => ['repository' => ['pullRequest' => ['reviewThreads' => ['nodes' => $threads]]]]]),
        'api.github.com/repos/acme/app/pulls/7' => Http::response(roundPullRequest($headSha)),
        'api.github.com/repos/acme/app/commits/*/check-runs*' => Http::response(['check_runs' => $checkRuns]),
        'api.github.com/*' => Http::response([]),
    ]);
}

function postRoundEvent(string $event, array $payload): void
{
    $payload += ['repository' => ['id' => 555, 'full_name' => 'acme/app']];

    test()->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => $event,
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'secret'),
    ])->assertOk();
}

function roundReviews(): int
{
    return YakTask::where('mode', TaskMode::Review)->where('external_id', ROUND_PR_URL)->where('status', 'pending')->count();
}

function thread(bool $resolved, int $commentId): array
{
    return ['id' => "t{$commentId}", 'isResolved' => $resolved, 'comments' => ['nodes' => [['databaseId' => $commentId]]]];
}

function resolveThread(): void
{
    postRoundEvent('pull_request_review_thread', ['action' => 'resolved', 'pull_request' => roundPullRequest()]);
}

it('starts a review when the last Yak thread is resolved', function () {
    fakeRoundGitHub([thread(true, 900), thread(false, 5)]);

    resolveThread();

    expect(roundReviews())->toBe(1);
});

it('does not start a review while a Yak thread is unresolved', function () {
    fakeRoundGitHub([thread(true, 901), thread(false, 900)]);

    resolveThread();

    expect(roundReviews())->toBe(0);
});

it('starts a review on a /yak review comment', function (string $body, int $expected) {
    fakeRoundGitHub();

    postRoundEvent('issue_comment', [
        'action' => 'created',
        'issue' => ['number' => 7, 'user' => ['login' => 'dev'], 'pull_request' => ['html_url' => ROUND_PR_URL]],
        'comment' => ['body' => $body, 'user' => ['login' => 'dev', 'type' => 'User'], 'author_association' => 'NONE'],
    ]);

    expect(roundReviews())->toBe($expected);
})->with([
    'exact' => [" /Yak Review\n", 1],
    'other text' => ['/yak reviewing this', 0],
]);

it('only accepts /yak review from the PR author or a collaborator', function (string $login, string $type, string $association, int $expected) {
    fakeRoundGitHub();

    postRoundEvent('issue_comment', [
        'action' => 'created',
        'issue' => ['number' => 7, 'user' => ['login' => 'dev'], 'pull_request' => ['html_url' => ROUND_PR_URL]],
        'comment' => ['body' => '/yak review', 'user' => ['login' => $login, 'type' => $type], 'author_association' => $association],
    ]);

    expect(roundReviews())->toBe($expected);
})->with([
    'stranger' => ['rando', 'User', 'NONE', 0],
    'bot' => ['helper[bot]', 'Bot', 'COLLABORATOR', 0],
    'collaborator' => ['rando', 'User', 'COLLABORATOR', 1],
    'pr author' => ['dev', 'User', 'NONE', 1],
]);

it('starts no second round when the shared review gates refuse the PR', function (string $gate) {
    $pullRequest = roundPullRequest();
    match ($gate) {
        'disabled' => $this->repository->update(['pr_review_enabled' => false]),
        'draft' => $pullRequest['draft'] = true,
        'bot' => (function () use (&$pullRequest) {
            config()->set('yak.pr_review.self_review_enabled', false);
            $pullRequest['user']['login'] = app(AppService::class)->appBotLogin();
        })(),
    };
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/app/pulls/7' => Http::response($pullRequest),
        'api.github.com/*' => Http::response([]),
    ]);

    app(StartSecondRound::class)->handle($this->repository->fresh(), $pullRequest, 'comment');

    expect(roundReviews())->toBe(0);
})->with(['disabled', 'draft', 'bot']);

it('starts a second round for out-of-diff findings that have no comment rows', function () {
    $this->review->comments()->delete();
    fakeRoundGitHub();

    postRoundEvent('issue_comment', [
        'action' => 'created',
        'issue' => ['number' => 7, 'user' => ['login' => 'dev'], 'pull_request' => ['html_url' => ROUND_PR_URL]],
        'comment' => ['body' => '/yak review', 'user' => ['login' => 'dev', 'type' => 'User'], 'author_association' => 'NONE'],
    ]);

    expect(roundReviews())->toBe(1);
});

it('ignores a resolved human thread when Yak has no threads', function () {
    $this->review->comments()->delete();
    $this->review->update(['risk_assessment' => ['event' => 'COMMENT', 'findings_count' => 1]]);
    fakeRoundGitHub([thread(true, 5)]);

    resolveThread();

    expect(roundReviews())->toBe(0);
});

it('starts one review per head sha', function () {
    fakeRoundGitHub([thread(true, 900)]);

    resolveThread();
    resolveThread();

    expect(roundReviews())->toBe(1);

    PrReview::factory()->create([
        'yak_task_id' => YakTask::factory()->create(['mode' => TaskMode::Review]),
        'repo' => 'acme/app', 'pr_number' => 7, 'pr_url' => ROUND_PR_URL,
        'commit_sha_reviewed' => 'sha-new', 'risk_assessment' => ['event' => 'COMMENT', 'reasons' => []],
    ]);
    YakTask::where('mode', TaskMode::Review)->where('external_id', ROUND_PR_URL)->delete();

    resolveThread();

    expect(roundReviews())->toBe(0);
});

it('starts nothing at level 1 or when the latest review had no findings', function () {
    fakeRoundGitHub([thread(true, 900)]);

    $this->repository->update(['approval_level' => 1]);
    resolveThread();
    expect(roundReviews())->toBe(0);

    $this->repository->update(['approval_level' => 2]);
    $this->review->comments()->delete();
    $this->review->update(['risk_assessment' => ['event' => 'APPROVE', 'reasons' => []]]);
    resolveThread();
    expect(roundReviews())->toBe(0);
});

it('does not schedule the quiet period job at level 1', function () {
    fakeRoundGitHub();
    $this->repository->update(['approval_level' => 1]);

    postRoundEvent('pull_request', ['action' => 'synchronize', 'pull_request' => roundPullRequest()]);

    Queue::assertNotPushed(QuietPeriodReviewJob::class);
});

it('schedules the quiet period job on push', function () {
    fakeRoundGitHub();

    postRoundEvent('pull_request', ['action' => 'synchronize', 'pull_request' => roundPullRequest()]);

    Queue::assertPushed(QuietPeriodReviewJob::class, fn (QuietPeriodReviewJob $job) => $job->headSha === 'sha-new'
        && $job->delay !== null);
});

it('starts a review after the quiet period only when the head is unchanged and checks are done', function (string $headSha, array $checkRuns, int $expected) {
    fakeRoundGitHub([], $headSha, $checkRuns);

    app()->call([new QuietPeriodReviewJob($this->repository->id, 7, 'sha-new'), 'handle']);

    expect(roundReviews())->toBe($expected);
})->with([
    'quiet' => ['sha-new', [['status' => 'completed']], 1],
    'head moved' => ['sha-newer', [['status' => 'completed']], 0],
    'check running' => ['sha-new', [['status' => 'completed'], ['status' => 'in_progress']], 0],
]);
