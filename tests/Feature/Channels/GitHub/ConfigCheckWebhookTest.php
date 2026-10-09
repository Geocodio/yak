<?php

use App\Jobs\RunConfigCheckJob;
use App\Models\Repository;
use App\Providers\ChannelServiceProvider;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('yak.channels.github', [
        'app_id' => '123',
        'private_key' => 'key',
        'webhook_secret' => 'secret',
        'app_bot_login' => 'yak-bot[bot]',
    ]);
    (new ChannelServiceProvider(app()))->boot();
    Queue::fake();
});

function postConfigCheckPullRequest(string $action, string $fullName = 'example-org/example-repo'): void
{
    $payload = [
        'action' => $action,
        'number' => 42,
        'pull_request' => [
            'html_url' => 'https://github.com/example-org/example-repo/pull/42',
            'number' => 42, 'title' => '', 'body' => '', 'draft' => false,
            'user' => ['login' => 'dev'],
            'head' => ['ref' => 'feat/x', 'sha' => 'abcd1234'],
            'base' => ['ref' => 'main', 'sha' => 'base'],
            'state' => 'open',
        ],
        'repository' => ['full_name' => $fullName],
    ];

    test()->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'secret'),
    ])->assertOk();
}

it('dispatches the config check for pull request events that move the head', function (string $action) {
    $repository = Repository::factory()->create(['slug' => 'example-org/example-repo', 'is_active' => true, 'deployments_enabled' => false]);

    postConfigCheckPullRequest($action);

    Queue::assertPushed(RunConfigCheckJob::class, fn (RunConfigCheckJob $job): bool => $job->repositoryId === $repository->id
        && $job->pullRequestNumber === 42
        && $job->headSha === 'abcd1234');
})->with(['opened', 'reopened', 'ready_for_review', 'synchronize']);

it('keeps the synchronize response unchanged', function () {
    Repository::factory()->create(['slug' => 'example-org/example-repo', 'is_active' => true, 'deployments_enabled' => false]);

    $payload = ['action' => 'synchronize', 'pull_request' => ['number' => 42, 'head' => ['ref' => 'feat/x', 'sha' => 'abcd1234']], 'repository' => ['full_name' => 'example-org/example-repo']];
    $this->postJson('/webhooks/ci/github', $payload, [
        'X-GitHub-Event' => 'pull_request',
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'secret'),
    ])->assertOk()->assertJson(['skipped' => 'synchronize does not trigger review']);
});

it('dispatches nothing for an unknown repository or an unrelated action', function () {
    Repository::factory()->create(['slug' => 'example-org/example-repo', 'is_active' => true, 'deployments_enabled' => false]);

    postConfigCheckPullRequest('opened', 'someone/else');
    postConfigCheckPullRequest('labeled');

    Queue::assertNotPushed(RunConfigCheckJob::class);
});
