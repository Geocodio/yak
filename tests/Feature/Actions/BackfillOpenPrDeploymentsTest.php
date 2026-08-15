<?php

use App\Actions\BackfillOpenPrDeployments;
use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\DeploymentStatus;
use App\Jobs\Deployments\DeployBranchJob;
use App\Models\BranchDeployment;
use App\Models\Repository;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
    config()->set('yak.channels.github.installation_id', 12345);
});

function fakeGitHubReturning(array $prs): void
{
    $github = mock(GitHubAppService::class);
    $github->shouldReceive('listOpenPullRequests')->andReturn($prs);
    app()->instance(GitHubAppService::class, $github);
}

function pr(string $ref, string $sha, int $number, bool $draft = false, string $login = 'maria'): array
{
    return [
        'number' => $number,
        'draft' => $draft,
        'user' => ['login' => $login],
        'head' => ['ref' => $ref, 'sha' => $sha],
    ];
}

function eligibleRepo(): Repository
{
    return Repository::factory()->create([
        'deployments_enabled' => true,
        'setup_status' => 'ready',
        'current_template_version' => 1,
    ]);
}

it('creates a Pending deployment and dispatches DeployBranchJob for an open PR with no deployment', function () {
    $repo = eligibleRepo();
    fakeGitHubReturning([pr('feature-x', 'abc123', 7)]);

    $created = app(BackfillOpenPrDeployments::class)($repo);

    expect($created)->toBe(1);

    $deployment = BranchDeployment::where('repository_id', $repo->id)
        ->where('branch_name', 'feature-x')
        ->firstOrFail();

    expect($deployment->status)->toBe(DeploymentStatus::Pending)
        ->and($deployment->pr_number)->toBe(7)
        ->and($deployment->pr_state)->toBe('open')
        ->and($deployment->current_commit_sha)->toBe('abc123');

    Bus::assertDispatched(DeployBranchJob::class, fn ($job) => $job->deploymentId === $deployment->id);
});

it('leaves an existing deployment untouched and does not redeploy it', function () {
    $repo = eligibleRepo();
    $existing = BranchDeployment::factory()->running()->create([
        'repository_id' => $repo->id,
        'branch_name' => 'feature-x',
        'pr_number' => 7,
        'current_commit_sha' => 'oldsha',
    ]);

    fakeGitHubReturning([pr('feature-x', 'newsha', 7)]);

    $created = app(BackfillOpenPrDeployments::class)($repo);

    expect($created)->toBe(0)
        ->and($existing->fresh()->status)->toBe(DeploymentStatus::Running)
        ->and($existing->fresh()->current_commit_sha)->toBe('oldsha');

    Bus::assertNotDispatched(DeployBranchJob::class);
});

it('deploys draft and Yak-authored PRs too (parity with the opened webhook)', function () {
    $repo = eligibleRepo();
    fakeGitHubReturning([
        pr('draft-branch', 's1', 1, draft: true),
        pr('bot-branch', 's2', 2, login: 'yak-bot[bot]'),
    ]);

    $created = app(BackfillOpenPrDeployments::class)($repo);

    expect($created)->toBe(2)
        ->and(BranchDeployment::where('repository_id', $repo->id)->whereIn('branch_name', ['draft-branch', 'bot-branch'])->count())->toBe(2);
});

it('staggers boots past the running cap', function () {
    config()->set('yak.deployments.running_cap', 2);
    $repo = eligibleRepo();
    fakeGitHubReturning([
        pr('b1', 's1', 1),
        pr('b2', 's2', 2),
        pr('b3', 's3', 3),
        pr('b4', 's4', 4),
    ]);

    app(BackfillOpenPrDeployments::class)($repo);

    // First `cap` deployments boot immediately, the rest are delayed.
    Bus::assertDispatched(DeployBranchJob::class, 4);
    $delayed = collect(Bus::dispatched(DeployBranchJob::class))
        ->filter(fn ($job) => $job->delay !== null)
        ->count();
    expect($delayed)->toBe(2);
});
