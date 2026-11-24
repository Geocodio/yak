<?php

use App\Channels\GitHub\AppService;
use App\Jobs\RefreshPullRequestMergeabilityJob;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Providers\ChannelServiceProvider;
use App\Services\Threads\PullRequests\PullRequestFacts;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('yak.channels.github', [
        'app_id' => '123',
        'private_key' => 'key',
        'webhook_secret' => 'secret',
        'app_bot_login' => 'yak-bot[bot]',
        'installation_id' => 0,
    ]);
    (new ChannelServiceProvider(app()))->boot();
    Http::fake();

    Repository::factory()->create(['slug' => 'acme-app', 'github_full_name' => 'acme/app', 'default_branch' => 'main', 'ci_system' => 'github_actions']);
    $this->row = ThreadRepository::factory()->for(Thread::factory()->create())->withPullRequest(42)->create(['repo' => 'acme-app']);
});

function runMergeabilityJob(array $pullRequest): RefreshPullRequestMergeabilityJob
{
    $github = Mockery::mock(AppService::class);
    $github->shouldReceive('getPullRequest')->with(0, 'acme/app', 42)->andReturn($pullRequest);

    $job = (new RefreshPullRequestMergeabilityJob('acme/app', 42))->withFakeQueueInteractions();
    $job->handle($github, app(PullRequestFacts::class));

    return $job;
}

function postMergeabilityWebhook(string $event, array $payload): TestResponse
{
    return test()->postJson('/webhooks/github', $payload, [
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'secret'),
        'X-GitHub-Event' => $event,
    ]);
}

function mergeabilityPullRequestPayload(string $action, array $extra = []): array
{
    return array_merge([
        'action' => $action,
        'pull_request' => ['number' => 42, 'draft' => false, 'head' => ['sha' => 'abc'], 'created_at' => '2026-11-24T10:00:00Z'],
        'repository' => ['full_name' => 'acme/app'],
    ], $extra);
}

it('stores the mergeable state', function () {
    $job = runMergeabilityJob(['mergeable' => false, 'mergeable_state' => 'dirty']);

    expect($this->row->refresh()->pull_request_mergeable_state)->toBe('dirty');
    $job->assertNotReleased();
});

it('releases while github is still computing', function () {
    $this->row->forceFill(['pull_request_mergeable_state' => 'clean'])->save();

    $job = runMergeabilityJob(['mergeable' => null, 'mergeable_state' => 'unknown']);

    $job->assertReleased();
    expect($this->row->refresh()->pull_request_mergeable_state)->toBe('clean');
});

it('dispatches on synchronize and on base change', function () {
    Queue::fake();

    postMergeabilityWebhook('pull_request', mergeabilityPullRequestPayload('synchronize'));
    postMergeabilityWebhook('pull_request', mergeabilityPullRequestPayload('edited', ['changes' => ['base' => ['ref' => ['from' => 'dev']]]]));
    postMergeabilityWebhook('pull_request', mergeabilityPullRequestPayload('edited', ['changes' => ['title' => ['from' => 'x']]]));

    Queue::assertPushed(RefreshPullRequestMergeabilityJob::class, 2);
    Queue::assertPushed(fn (RefreshPullRequestMergeabilityJob $job) => $job->repoFullName === 'acme/app' && $job->prNumber === 42);
});

it('dispatches for every open PR on a default branch push', function () {
    Queue::fake();
    ThreadRepository::factory()->for(Thread::factory()->create())->withPullRequest(43)->create(['repo' => 'acme-app']);
    ThreadRepository::factory()->for(Thread::factory()->create())->withPullRequest(44, 'merged')->create(['repo' => 'acme-app']);

    postMergeabilityWebhook('push', ['ref' => 'refs/heads/main', 'after' => 'def', 'repository' => ['full_name' => 'acme/app']]);

    Queue::assertPushed(RefreshPullRequestMergeabilityJob::class, 2);
    Queue::assertPushed(fn (RefreshPullRequestMergeabilityJob $job) => $job->prNumber === 43);
});

it('skips an open row that has no pull request number on a default branch push', function () {
    Queue::fake();
    ThreadRepository::factory()->for(Thread::factory()->create())->create(['repo' => 'acme-app', 'pull_request_state' => 'open', 'pull_request_number' => null]);

    postMergeabilityWebhook('push', ['ref' => 'refs/heads/main', 'after' => 'def', 'repository' => ['full_name' => 'acme/app']]);

    Queue::assertPushed(RefreshPullRequestMergeabilityJob::class, 1);
    Queue::assertNotPushed(fn (RefreshPullRequestMergeabilityJob $job) => $job->prNumber === 0);
});

it('ignores a push to another branch', function () {
    Queue::fake();

    postMergeabilityWebhook('push', ['ref' => 'refs/heads/feature', 'after' => 'def', 'repository' => ['full_name' => 'acme/app']]);

    Queue::assertNotPushed(RefreshPullRequestMergeabilityJob::class);
});
