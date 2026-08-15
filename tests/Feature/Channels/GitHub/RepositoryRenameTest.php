<?php

use App\Jobs\Deployments\DeployBranchJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('yak.channels.github', [
        'app_id' => '123',
        'private_key' => 'key',
        'webhook_secret' => 'secret',
        'app_bot_login' => 'yak-bot[bot]',
    ]);
    (new ChannelServiceProvider(app()))->boot();
    Bus::fake();
});

function signRenamePayload(string $body): string
{
    return 'sha256=' . hash_hmac('sha256', $body, 'secret');
}

/**
 * @param  array<string, mixed>  $payload
 */
function postGitHubEvent(array $payload, string $event): TestResponse
{
    return test()->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => $event,
        'X-Hub-Signature-256' => signRenamePayload((string) json_encode($payload)),
    ]);
}

/**
 * @return array<string, mixed>
 */
function pullRequestOpenedPayload(string $fullName, ?int $repoId): array
{
    $repository = ['full_name' => $fullName];

    if ($repoId !== null) {
        $repository['id'] = $repoId;
    }

    return [
        'action' => 'opened',
        'number' => 7,
        'pull_request' => [
            'html_url' => "https://github.com/{$fullName}/pull/7",
            'number' => 7, 'title' => '', 'body' => '', 'draft' => false,
            'user' => ['login' => 'dev'],
            'head' => ['ref' => 'feat/x', 'sha' => 'abcd1234'],
            'base' => ['ref' => 'main', 'sha' => 'base'],
            'state' => 'open',
        ],
        'repository' => $repository,
    ];
}

it('resolves a repository by github_repo_id when the GitHub name no longer matches the slug', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-org/old-name',
        'github_repo_id' => 555,
        'github_full_name' => 'example-org/new-name',
        'is_active' => true,
        'deployments_enabled' => true,
        'current_template_version' => 1,
    ]);

    postGitHubEvent(pullRequestOpenedPayload('example-org/new-name', 555), 'pull_request')->assertOk();

    Bus::assertDispatched(DeployBranchJob::class);
    $this->assertDatabaseHas('branch_deployments', [
        'repository_id' => $repo->id,
        'branch_name' => 'feat/x',
    ]);
});

it('resolves a repository by github_full_name when the payload carries no repo id', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-org/old-name',
        'github_repo_id' => null,
        'github_full_name' => 'example-org/new-name',
        'is_active' => true,
        'deployments_enabled' => true,
        'current_template_version' => 1,
    ]);

    postGitHubEvent(pullRequestOpenedPayload('example-org/new-name', null), 'pull_request')->assertOk();

    $this->assertDatabaseHas('branch_deployments', ['repository_id' => $repo->id]);
});

it('still resolves a repository by slug when no GitHub identity has been recorded', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-org/legacy-repo',
        'github_repo_id' => null,
        'github_full_name' => null,
        'is_active' => true,
        'deployments_enabled' => true,
        'current_template_version' => 1,
    ]);

    postGitHubEvent(pullRequestOpenedPayload('example-org/legacy-repo', 999), 'pull_request')->assertOk();

    $this->assertDatabaseHas('branch_deployments', ['repository_id' => $repo->id]);
});

it('prefers the github_repo_id match over a slug match on a different repository', function () {
    $renamed = Repository::factory()->create([
        'slug' => 'example-org/old-name',
        'github_repo_id' => 555,
        'github_full_name' => 'example-org/new-name',
        'deployments_enabled' => true,
        'current_template_version' => 1,
    ]);

    $imposter = Repository::factory()->create([
        'slug' => 'example-org/new-name',
        'github_repo_id' => 777,
        'github_full_name' => 'example-org/new-name',
        'deployments_enabled' => true,
        'current_template_version' => 1,
    ]);

    postGitHubEvent(pullRequestOpenedPayload('example-org/new-name', 555), 'pull_request')->assertOk();

    $this->assertDatabaseHas('branch_deployments', ['repository_id' => $renamed->id]);
    $this->assertDatabaseMissing('branch_deployments', ['repository_id' => $imposter->id]);
});

it('records the new GitHub name and clone URL on a repository.renamed event', function () {
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/provisioner',
        'github_repo_id' => 555,
        'github_full_name' => 'geocodio/provisioner',
        'git_url' => 'https://github.com/geocodio/provisioner.git',
    ]);

    postGitHubEvent([
        'action' => 'renamed',
        'changes' => ['repository' => ['name' => ['from' => 'provisioner']]],
        'repository' => [
            'id' => 555,
            'name' => 'infrastructure',
            'full_name' => 'geocodio/infrastructure',
            'clone_url' => 'https://github.com/geocodio/infrastructure.git',
        ],
    ], 'repository')->assertOk();

    $repo->refresh();

    expect($repo->github_full_name)->toBe('geocodio/infrastructure')
        ->and($repo->git_url)->toBe('https://github.com/geocodio/infrastructure.git');
});

it('leaves the slug and task associations untouched when a repository is renamed', function () {
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/provisioner',
        'github_repo_id' => 555,
        'github_full_name' => 'geocodio/provisioner',
    ]);
    $task = YakTask::factory()->create(['repo' => 'geocodio/provisioner']);

    postGitHubEvent([
        'action' => 'renamed',
        'changes' => ['repository' => ['name' => ['from' => 'provisioner']]],
        'repository' => [
            'id' => 555,
            'name' => 'infrastructure',
            'full_name' => 'geocodio/infrastructure',
            'clone_url' => 'https://github.com/geocodio/infrastructure.git',
        ],
    ], 'repository')->assertOk();

    expect($repo->refresh()->slug)->toBe('geocodio/provisioner')
        ->and($task->refresh()->repo)->toBe('geocodio/provisioner')
        ->and($task->repository)->not->toBeNull();
});

it('records the new owner on a repository.transferred event', function () {
    $repo = Repository::factory()->create([
        'slug' => 'old-org/widgets',
        'github_repo_id' => 555,
        'github_full_name' => 'old-org/widgets',
    ]);

    postGitHubEvent([
        'action' => 'transferred',
        'changes' => ['owner' => ['from' => ['user' => ['login' => 'old-org']]]],
        'repository' => [
            'id' => 555,
            'name' => 'widgets',
            'full_name' => 'new-org/widgets',
            'clone_url' => 'https://github.com/new-org/widgets.git',
        ],
    ], 'repository')->assertOk();

    expect($repo->refresh()->github_full_name)->toBe('new-org/widgets');
});

it('ignores a repository event for a repository Yak does not track', function () {
    postGitHubEvent([
        'action' => 'renamed',
        'changes' => ['repository' => ['name' => ['from' => 'whatever']]],
        'repository' => [
            'id' => 4242,
            'name' => 'unknown',
            'full_name' => 'someone-else/unknown',
            'clone_url' => 'https://github.com/someone-else/unknown.git',
        ],
    ], 'repository')->assertOk();

    expect(Repository::where('github_full_name', 'someone-else/unknown')->exists())->toBeFalse();
});

it('ignores repository events other than renamed and transferred', function () {
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/api',
        'github_repo_id' => 555,
        'github_full_name' => 'geocodio/api',
    ]);

    postGitHubEvent([
        'action' => 'edited',
        'repository' => [
            'id' => 555,
            'name' => 'api',
            'full_name' => 'geocodio/renamed-behind-our-back',
            'clone_url' => 'https://github.com/geocodio/renamed-behind-our-back.git',
        ],
    ], 'repository')->assertOk();

    expect($repo->refresh()->github_full_name)->toBe('geocodio/api');
});

it('falls back to the slug when no GitHub name has been recorded', function () {
    $repo = Repository::factory()->create([
        'slug' => 'geocodio/api',
        'github_full_name' => null,
    ]);

    expect($repo->github_full_name)->toBe('geocodio/api');
});
