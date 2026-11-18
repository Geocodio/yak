<?php

use App\Jobs\HandleCiJobFailureJob;
use App\Jobs\RecordCiJobGreenJob;
use App\Models\CiDefaultBranchGreen;
use App\Models\CiJobFailure;
use App\Models\Repository;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config()->set('yak.channels.github', ['app_id' => '123', 'private_key' => 'key', 'webhook_secret' => 'secret']);
    (new ChannelServiceProvider(app()))->boot();

    $this->repository = Repository::factory()->create([
        'slug' => 'widgets-slug',
        'github_full_name' => 'acme/widgets',
        'ci_system' => 'github_actions',
        'default_branch' => 'main',
    ]);
});

/**
 * @param  array<string, mixed>  $payload
 */
function postCiFailureWebhook(string $event, array $payload): void
{
    test()->postJson('/webhooks/github', $payload, [
        'X-GitHub-Event' => $event,
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'secret'),
    ])->assertOk();
}

/**
 * @param  array<string, mixed>  $job
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ciFailureWorkflowJobPayload(array $job = [], array $overrides = []): array
{
    return array_replace_recursive([
        'action' => 'completed',
        'workflow_job' => array_merge([
            'name' => 'Test (Main)',
            'workflow_name' => 'CI',
            'conclusion' => 'failure',
            'head_branch' => 'feature/x',
            'completed_at' => '2026-11-18T10:00:00Z',
        ], $job),
        'repository' => ['id' => 1, 'full_name' => 'acme/widgets'],
        'sender' => ['login' => 'octocat', 'type' => 'User'],
    ], $overrides);
}

it('ignores workflow_job actions other than completed', function () {
    postCiFailureWebhook('workflow_job', ciFailureWorkflowJobPayload(overrides: ['action' => 'in_progress']));

    Queue::assertNothingPushed();
});

it('dispatches the failure job for a failed or timed_out job on an active github_actions repo', function (string $conclusion) {
    postCiFailureWebhook('workflow_job', ciFailureWorkflowJobPayload(['conclusion' => $conclusion]));

    Queue::assertPushed(HandleCiJobFailureJob::class, fn ($job) => $job->repositoryId === $this->repository->id
        && $job->workflowJobPayload['workflow_job']['conclusion'] === $conclusion);
})->with(['failure', 'timed_out']);

it('ignores cancelled and skipped conclusions', function (string $conclusion) {
    postCiFailureWebhook('workflow_job', ciFailureWorkflowJobPayload(['conclusion' => $conclusion]));

    Queue::assertNothingPushed();
})->with(['cancelled', 'skipped']);

it('ignores yak branches, configured prefixes, bot senders and a null head_branch', function (array $job, array $overrides) {
    config()->set('yak.ci_failure.ignored_branch_prefixes', ['yak/', 'dependabot/']);

    postCiFailureWebhook('workflow_job', ciFailureWorkflowJobPayload($job, $overrides));

    Queue::assertNothingPushed();
})->with([
    'yak branch' => [['head_branch' => 'yak/fix-1'], []],
    'configured prefix' => [['head_branch' => 'dependabot/npm/foo'], []],
    'bot type' => [[], ['sender' => ['type' => 'Bot']]],
    'bot login' => [[], ['sender' => ['login' => 'renovate[bot]']]],
    'null branch' => [['head_branch' => null], []],
]);

it('ignores excluded jobs', function (string $name) {
    postCiFailureWebhook('workflow_job', ciFailureWorkflowJobPayload(['name' => $name]));

    Queue::assertNothingPushed();
})->with(['Deploy staging', 'Notify Slack (tests)']);

it('ignores repos that are inactive or not on github_actions', function (array $state) {
    $this->repository->update($state);

    postCiFailureWebhook('workflow_job', ciFailureWorkflowJobPayload());

    Queue::assertNothingPushed();
})->with([
    'inactive' => [['is_active' => false]],
    'drone' => [['ci_system' => 'drone']],
]);

/**
 * @return array<string, mixed>
 */
function ciFailureGreenPayload(string $branch = 'main'): array
{
    return ciFailureWorkflowJobPayload(['conclusion' => 'success', 'head_branch' => $branch, 'run_id' => 77, 'head_sha' => 'sha-1']);
}

/**
 * @param  array<string, mixed>  $payload
 */
function runCiFailureGreenJob(Repository $repository, array $payload, string $headRepository): void
{
    configureFakeGitHubApp();
    Http::fake(gitHubTokenFake() + [
        'api.github.com/repos/acme/widgets/actions/runs/77' => Http::response([
            'head_repository' => ['full_name' => $headRepository],
            'head_sha' => 'sha-1',
        ]),
    ]);

    app()->call([new RecordCiJobGreenJob($repository->id, $payload), 'handle']);
}

function widgetsFailure(string $branch): CiJobFailure
{
    return CiJobFailure::factory()->create(['repo' => 'widgets-slug', 'branch' => $branch]);
}

it('dispatches the green job for a default-branch success or a match with an unresolved failure', function (string $branch) {
    widgetsFailure('feature/y');

    postCiFailureWebhook('workflow_job', ciFailureGreenPayload($branch));

    Queue::assertPushed(RecordCiJobGreenJob::class, fn ($job) => $job->repositoryId === $this->repository->id);
})->with(['main', 'feature/y']);

it('dispatches no green job when nothing matches and the branch is not default', function () {
    postCiFailureWebhook('workflow_job', ciFailureGreenPayload('feature/z'));

    Queue::assertNothingPushed();
});

it('records a green from the repo own run', function () {
    $matching = widgetsFailure('main');
    $otherBranch = widgetsFailure('feature/y');
    $otherJob = CiJobFailure::factory()->create(['repo' => 'widgets-slug', 'branch' => 'main', 'job_name' => 'Lint']);

    runCiFailureGreenJob($this->repository, ciFailureGreenPayload(), 'acme/widgets');

    expect($matching->fresh()->resolution)->toBe('green')
        ->and($otherBranch->fresh()->resolved_at)->toBeNull()
        ->and($otherJob->fresh()->resolved_at)->toBeNull()
        ->and(CiDefaultBranchGreen::where('repo', 'acme/widgets')->count())->toBe(0)
        ->and(CiDefaultBranchGreen::latestFor('widgets-slug', 'CI', 'Test (Main)')?->toIso8601String())
        ->toBe('2026-11-18T10:00:00+00:00');
});

it('ignores a green from a fork run', function () {
    $failure = widgetsFailure('main');

    runCiFailureGreenJob($this->repository, ciFailureGreenPayload(), 'fork/widgets');

    expect($failure->fresh()->resolved_at)->toBeNull()
        ->and(CiDefaultBranchGreen::count())->toBe(0);
});

it('ignores a green whose run head sha differs from the job', function () {
    $payload = ciFailureGreenPayload();
    $payload['workflow_job']['head_sha'] = 'other-sha';

    runCiFailureGreenJob($this->repository, $payload, 'acme/widgets');

    expect(CiDefaultBranchGreen::count())->toBe(0);
});

/**
 * @return array<string, mixed>
 */
function ciFailurePullRequestClosedPayload(string $headRef, string $headRepository = 'acme/widgets'): array
{
    return [
        'action' => 'closed',
        'pull_request' => [
            'html_url' => 'https://github.com/acme/widgets/pull/9',
            'head' => ['ref' => $headRef, 'repo' => ['full_name' => $headRepository]],
        ],
        'repository' => ['id' => 1, 'full_name' => 'acme/widgets'],
    ];
}

it('resolves a branch failures when the branch is deleted or its PR closes', function (string $event, array $payload, string $resolution) {
    $failure = widgetsFailure('feature/x');
    $other = widgetsFailure('feature/y');

    postCiFailureWebhook($event, $payload);

    expect($failure->fresh()->resolution)->toBe($resolution)
        ->and($other->fresh()->resolved_at)->toBeNull();
})->with([
    'branch deleted' => ['delete', ['ref_type' => 'branch', 'ref' => 'feature/x', 'repository' => ['id' => 1, 'full_name' => 'acme/widgets']], 'branch_deleted'],
    'pr closed' => ['pull_request', ciFailurePullRequestClosedPayload('feature/x'), 'pr_closed'],
]);

it('does not resolve failures for a pull_request closed from a fork', function () {
    $failure = widgetsFailure('feature/x');

    postCiFailureWebhook('pull_request', ciFailurePullRequestClosedPayload('feature/x', 'fork/widgets'));

    expect($failure->fresh()->resolved_at)->toBeNull();
});

it('does not resolve the default branch when a PR with head ref master closes', function () {
    $this->repository->update(['default_branch' => 'master']);
    $failure = widgetsFailure('master');

    postCiFailureWebhook('pull_request', ciFailurePullRequestClosedPayload('master'));

    expect($failure->fresh()->resolved_at)->toBeNull();
});
