<?php

use App\Jobs\Deployments\DeployBranchJob;
use App\Jobs\Deployments\DestroyDeploymentJob;
use App\Jobs\Deployments\UpdateDeploymentJob;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Bus;

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

function signGitHubWebhook(string $body): string
{
    return 'sha256=' . hash_hmac('sha256', $body, 'secret');
}

it('pull_request.opened on a deployments-enabled repo dispatches DeployBranchJob', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-org/example-repo',
        'is_active' => true,
        'deployments_enabled' => true,
        'current_template_version' => 1,
    ]);

    $payload = [
        'action' => 'opened',
        'number' => 42,
        'pull_request' => [
            'html_url' => 'https://github.com/example-org/example-repo/pull/42',
            'number' => 42, 'title' => '', 'body' => '', 'draft' => false,
            'user' => ['login' => 'dev'],
            'head' => ['ref' => 'feat/x', 'sha' => 'abcd1234'],
            'base' => ['ref' => 'main', 'sha' => 'base'],
            'state' => 'open',
        ],
        'repository' => ['full_name' => 'example-org/example-repo'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGitHubWebhook($body),
    ])->assertOk();

    Bus::assertDispatched(DeployBranchJob::class);
    $this->assertDatabaseHas('branch_deployments', [
        'repository_id' => $repo->id,
        'branch_name' => 'feat/x',
        'pr_number' => 42,
    ]);
});

it('pull_request.opened on a deployments-disabled repo is a deployment no-op', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-org/example-repo',
        'is_active' => true,
        'deployments_enabled' => false,
    ]);

    $payload = [
        'action' => 'opened',
        'pull_request' => [
            'html_url' => 'https://github.com/example-org/example-repo/pull/1',
            'number' => 1, 'title' => '', 'body' => '', 'draft' => false,
            'user' => ['login' => 'dev'],
            'head' => ['ref' => 'feat/x', 'sha' => 'abc'],
            'base' => ['ref' => 'main', 'sha' => 'b'],
            'state' => 'open',
        ],
        'repository' => ['full_name' => 'example-org/example-repo'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGitHubWebhook($body),
    ])->assertOk();

    Bus::assertNotDispatched(DeployBranchJob::class);
    $this->assertDatabaseCount('branch_deployments', 0);
});

it('push to an active deployment branch dispatches UpdateDeploymentJob', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-org/example-repo',
        'is_active' => true,
        'deployments_enabled' => true,
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->running()->create(['branch_name' => 'feat/x']);

    $payload = [
        'ref' => 'refs/heads/feat/x',
        'after' => 'newsha5678',
        'forced' => false,
        'repository' => ['full_name' => 'example-org/example-repo'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'push',
        'X-Hub-Signature-256' => signGitHubWebhook($body),
    ])->assertOk();

    Bus::assertDispatched(UpdateDeploymentJob::class, fn ($job) => $job->deploymentId === $deployment->id && $job->commitSha === 'newsha5678'
    );
});

it('pull_request.closed dispatches DestroyDeploymentJob', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-org/example-repo',
        'is_active' => true,
        'deployments_enabled' => true,
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->running()->create(['branch_name' => 'feat/x']);

    $payload = [
        'action' => 'closed',
        'number' => 1,
        'pull_request' => [
            'html_url' => 'https://github.com/example-org/example-repo/pull/1',
            'number' => 1, 'state' => 'closed', 'merged' => false, 'draft' => false,
            'user' => ['login' => 'dev'],
            'head' => ['ref' => 'feat/x', 'sha' => 'abc'],
            'base' => ['ref' => 'main', 'sha' => 'b'],
        ],
        'repository' => ['full_name' => 'example-org/example-repo'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGitHubWebhook($body),
    ])->assertOk();

    Bus::assertDispatched(DestroyDeploymentJob::class, fn ($job) => $job->deploymentId === $deployment->id);
});

it('delete event on a deployment branch dispatches DestroyDeploymentJob', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-org/example-repo',
        'is_active' => true,
        'deployments_enabled' => true,
    ]);
    $deployment = BranchDeployment::factory()->for($repo)->running()->create(['branch_name' => 'feat/x']);

    $payload = [
        'ref_type' => 'branch',
        'ref' => 'feat/x',
        'repository' => ['full_name' => 'example-org/example-repo'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'delete',
        'X-Hub-Signature-256' => signGitHubWebhook($body),
    ])->assertOk();

    Bus::assertDispatched(DestroyDeploymentJob::class, fn ($job) => $job->deploymentId === $deployment->id);
});

it('delete event with ref_type tag is ignored', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-org/example-repo',
        'is_active' => true,
        'deployments_enabled' => true,
    ]);
    BranchDeployment::factory()->for($repo)->running()->create(['branch_name' => 'feat/x']);

    $payload = [
        'ref_type' => 'tag',
        'ref' => 'v1.0.0',
        'repository' => ['full_name' => 'example-org/example-repo'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'delete',
        'X-Hub-Signature-256' => signGitHubWebhook($body),
    ])->assertOk();

    Bus::assertNotDispatched(DestroyDeploymentJob::class);
});

it('skips deployment when repo has no versioned template (current_template_version = 0)', function () {
    $repo = Repository::factory()->create([
        'slug' => 'example-org/example-repo',
        'is_active' => true,
        'deployments_enabled' => true,
        'current_template_version' => 0,
    ]);

    $payload = [
        'action' => 'opened',
        'pull_request' => [
            'html_url' => 'https://github.com/example-org/example-repo/pull/99',
            'number' => 99, 'title' => '', 'body' => '', 'draft' => false,
            'user' => ['login' => 'dev'],
            'head' => ['ref' => 'feat/needs-setup', 'sha' => 'abc'],
            'base' => ['ref' => 'main', 'sha' => 'b'],
            'state' => 'open',
        ],
        'repository' => ['full_name' => 'example-org/example-repo'],
    ];
    $body = json_encode($payload);

    $this->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => signGitHubWebhook($body),
    ])->assertOk();

    Bus::assertNotDispatched(DeployBranchJob::class);
    $this->assertDatabaseCount('branch_deployments', 0);
});
