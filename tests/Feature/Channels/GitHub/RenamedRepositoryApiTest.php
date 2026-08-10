<?php

use App\Channels\GitHub\ActionsBuildScanner;
use App\Channels\GitHub\NotificationDriver;
use App\Channels\GitHub\PollPullRequestReactionsJob;
use App\Enums\DeploymentStatus;
use App\Enums\NotificationType;
use App\Jobs\CreatePullRequestJob;
use App\Models\BranchDeployment;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\DeploymentGitHubSync;
use App\Services\HealthCheck\RepositoriesCheck;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);

    config()->set('yak.channels.github.app_id', '12345');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 99999);

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_token',
            'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        'api.github.com/repos/*/pulls' => Http::response([
            'number' => 42,
            'html_url' => 'https://github.com/geocodio/infrastructure/pull/42',
        ]),
        'api.github.com/*' => Http::response([]),
    ]);
});

/**
 * A repository Yak still knows by its original slug, renamed on GitHub.
 */
function renamedRepository(array $attributes = []): Repository
{
    return Repository::factory()->create(array_merge([
        'slug' => 'geocodio/provisioner',
        'github_repo_id' => 555,
        'github_full_name' => 'geocodio/infrastructure',
        'path' => '/home/yak/repos/provisioner',
        'default_branch' => 'main',
        'is_active' => true,
    ], $attributes));
}

it('opens pull requests against the current GitHub name, not the slug', function () {
    Process::fake(['git diff --name-only *' => Process::result('src/Thing.php')]);

    renamedRepository();

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'geocodio/provisioner',
        'branch_name' => 'yak/FIX-1',
        'source' => 'slack',
        'description' => 'Fix a thing',
        'result_summary' => 'Fixed it',
        'attempts' => 1,
    ]);

    app()->call([new CreatePullRequestJob($task), 'handle']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.github.com/repos/geocodio/infrastructure/pulls'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'repos/geocodio/provisioner'));
});

it('comments on pull requests using the current GitHub name', function () {
    renamedRepository();

    $task = YakTask::factory()->create([
        'repo' => 'geocodio/provisioner',
        'pr_url' => 'https://github.com/geocodio/infrastructure/pull/42',
    ]);

    app(NotificationDriver::class)->send($task, NotificationType::Progress, 'still working');

    Http::assertSent(fn ($request) => str_contains(
        $request->url(),
        'api.github.com/repos/geocodio/infrastructure/issues/42/comments',
    ));
});

it('scans Actions runs under the current GitHub name', function () {
    $repository = renamedRepository(['ci_system' => 'github_actions']);

    app(ActionsBuildScanner::class)->getRecentFailures($repository, 24);

    Http::assertSent(fn ($request) => str_contains(
        $request->url(),
        'api.github.com/repos/geocodio/infrastructure/actions/runs',
    ));
});

it('health-checks repository reachability under the current GitHub name', function () {
    renamedRepository();

    app(RepositoriesCheck::class)->run();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.github.com/repos/geocodio/infrastructure'));
});

it('polls review comment reactions under the current GitHub name', function () {
    renamedRepository();

    $review = PrReview::factory()->create(['repo' => 'geocodio/provisioner']);
    PrReviewComment::factory()->create([
        'pr_review_id' => $review->id,
        'github_comment_id' => 987,
    ]);

    app()->call([new PollPullRequestReactionsJob, 'handle']);

    Http::assertSent(fn ($request) => str_contains(
        $request->url(),
        'api.github.com/repos/geocodio/infrastructure/pulls/comments/987/reactions',
    ));
});

it('maps an internal slug to the current GitHub name', function () {
    renamedRepository();

    expect(Repository::githubNameFor('geocodio/provisioner'))->toBe('geocodio/infrastructure')
        ->and(Repository::githubNameFor('unknown/repo'))->toBe('unknown/repo');
});

it('creates GitHub deployments under the current GitHub name', function () {
    $repository = renamedRepository(['deployments_enabled' => true]);

    $deployment = BranchDeployment::factory()->create([
        'repository_id' => $repository->id,
        'branch_name' => 'feat/x',
        'current_commit_sha' => 'abc123',
        'github_deployment_id' => null,
    ]);

    app(DeploymentGitHubSync::class)->sync($deployment, DeploymentStatus::Starting);

    Http::assertSent(fn ($request) => str_contains(
        $request->url(),
        'api.github.com/repos/geocodio/infrastructure/deployments',
    ));
});
