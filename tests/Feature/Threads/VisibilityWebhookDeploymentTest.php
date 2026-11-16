<?php

use App\Jobs\Deployments\DeployBranchJob;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Bus;
use Tests\Support\VisibilityWorld;

beforeEach(function () {
    config()->set('yak.channels.github', [
        'app_id' => '123',
        'private_key' => 'key',
        'webhook_secret' => 'secret',
        'app_bot_login' => 'yak-bot[bot]',
    ]);
    (new ChannelServiceProvider(app()))->boot();
    Bus::fake();

    $this->world = VisibilityWorld::build();
    BranchDeployment::withoutGlobalScopes()->delete();
    Repository::where('slug', VisibilityWorld::REPO)->update(['deployments_enabled' => true, 'current_template_version' => 1]);
});

function postPullRequestEvent(string $action, string $branch, int $number = 77, string $repo = VisibilityWorld::REPO, ?string $headRepo = null)
{
    $payload = [
        'action' => $action,
        'number' => $number,
        'pull_request' => [
            'html_url' => "https://github.com/{$repo}/pull/{$number}",
            'number' => $number, 'title' => '', 'body' => '', 'draft' => false,
            'user' => ['login' => 'dev'],
            'head' => ['ref' => $branch, 'sha' => 'abcd1234', 'repo' => ['full_name' => $headRepo ?? $repo]],
            'base' => ['ref' => 'main', 'sha' => 'base'],
            'state' => 'open',
        ],
        'repository' => ['full_name' => $repo],
    ];
    $body = json_encode($payload);

    return test()->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'secret'),
    ]);
}

it('links a webhook-created deployment to the thread task and hides it from strangers', function () {
    postPullRequestEvent('opened', VisibilityWorld::BRANCH)->assertOk();
    Bus::assertDispatched(DeployBranchJob::class);

    $deployment = BranchDeployment::withoutGlobalScopes()->where('branch_name', VisibilityWorld::BRANCH)->sole();
    expect($deployment->yak_task_id)->toBe($this->world->task->id);

    foreach (['other', 'admin'] as $stranger) {
        $this->actingAs($this->world->{$stranger})->get("/deployments/{$deployment->public_id}")->assertNotFound();
        $this->actingAs($this->world->{$stranger})->get('/deployments?status=all')
            ->assertOk()->assertInertia(fn ($page) => $page->has('deployments.data', 0));
    }

    $this->actingAs($this->world->owner)->get("/deployments/{$deployment->public_id}")->assertOk();
});

it('leaves a deployment of a branch with no thread task unlinked and visible to all', function () {
    postPullRequestEvent('opened', 'feat/plain')->assertOk();

    $deployment = BranchDeployment::withoutGlobalScopes()->where('branch_name', 'feat/plain')->sole();
    expect($deployment->yak_task_id)->toBeNull();

    $this->actingAs($this->world->other)->get("/deployments/{$deployment->public_id}")->assertOk();
});

it('links an unlinked old deployment when its pull request is reopened', function () {
    BranchDeployment::factory()->create([
        'repository_id' => Repository::where('slug', VisibilityWorld::REPO)->value('id'),
        'branch_name' => VisibilityWorld::BRANCH,
        'yak_task_id' => null,
    ]);

    postPullRequestEvent('reopened', VisibilityWorld::BRANCH)->assertOk();

    expect(BranchDeployment::withoutGlobalScopes()->where('branch_name', VisibilityWorld::BRANCH)->sole()->yak_task_id)
        ->toBe($this->world->task->id);
});

it('leaves a fork pull request deployment unlinked even when a thread task carries the branch name', function () {
    postPullRequestEvent('opened', VisibilityWorld::BRANCH, 78, VisibilityWorld::REPO, 'forker/app')->assertOk();

    $deployment = BranchDeployment::withoutGlobalScopes()->where('branch_name', VisibilityWorld::BRANCH)->sole();
    expect($deployment->yak_task_id)->toBeNull();
});
