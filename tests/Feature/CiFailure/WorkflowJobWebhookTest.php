<?php

use App\Jobs\HandleCiJobFailureJob;
use App\Models\CiDefaultBranchGreen;
use App\Models\CiJobFailure;
use App\Models\Repository;
use App\Providers\ChannelServiceProvider;
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
function postWebhook(string $event, array $payload): void
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
function workflowJobPayload(array $job = [], array $overrides = []): array
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
    postWebhook('workflow_job', workflowJobPayload(overrides: ['action' => 'in_progress']));

    Queue::assertNothingPushed();
});

it('dispatches the failure job for a failed or timed_out job on an active github_actions repo', function (string $conclusion) {
    postWebhook('workflow_job', workflowJobPayload(['conclusion' => $conclusion]));

    Queue::assertPushed(HandleCiJobFailureJob::class, fn ($job) => $job->repositoryId === $this->repository->id
        && $job->workflowJobPayload['workflow_job']['conclusion'] === $conclusion);
})->with(['failure', 'timed_out']);

it('ignores cancelled and skipped conclusions', function (string $conclusion) {
    postWebhook('workflow_job', workflowJobPayload(['conclusion' => $conclusion]));

    Queue::assertNothingPushed();
})->with(['cancelled', 'skipped']);

it('ignores yak branches, configured prefixes, bot senders and a null head_branch', function (array $job, array $overrides) {
    config()->set('yak.ci_failure.ignored_branch_prefixes', ['yak/', 'dependabot/']);

    postWebhook('workflow_job', workflowJobPayload($job, $overrides));

    Queue::assertNothingPushed();
})->with([
    'yak branch' => [['head_branch' => 'yak/fix-1'], []],
    'configured prefix' => [['head_branch' => 'dependabot/npm/foo'], []],
    'bot type' => [[], ['sender' => ['type' => 'Bot']]],
    'bot login' => [[], ['sender' => ['login' => 'renovate[bot]']]],
    'null branch' => [['head_branch' => null], []],
]);

it('ignores excluded jobs', function (string $name) {
    postWebhook('workflow_job', workflowJobPayload(['name' => $name]));

    Queue::assertNothingPushed();
})->with(['Deploy staging', 'Notify Slack (tests)']);

it('ignores repos that are inactive or not on github_actions', function (array $state) {
    $this->repository->update($state);

    postWebhook('workflow_job', workflowJobPayload());

    Queue::assertNothingPushed();
})->with([
    'inactive' => [['is_active' => false]],
    'drone' => [['ci_system' => 'drone']],
]);

it('resolves the branch failures on a green run and records a default-branch green', function () {
    $matching = CiJobFailure::factory()->create(['repo' => 'widgets-slug', 'branch' => 'main']);
    $otherBranch = CiJobFailure::factory()->create(['repo' => 'widgets-slug', 'branch' => 'feature/y']);
    $otherJob = CiJobFailure::factory()->create(['repo' => 'widgets-slug', 'branch' => 'main', 'job_name' => 'Lint']);

    postWebhook('workflow_job', workflowJobPayload(['conclusion' => 'success', 'head_branch' => 'main']));

    expect($matching->fresh()->resolution)->toBe('green')
        ->and($matching->fresh()->resolved_at)->not->toBeNull()
        ->and($otherBranch->fresh()->resolved_at)->toBeNull()
        ->and($otherJob->fresh()->resolved_at)->toBeNull()
        ->and(CiDefaultBranchGreen::where('repo', 'acme/widgets')->count())->toBe(0)
        ->and(CiDefaultBranchGreen::latestFor('widgets-slug', 'CI', 'Test (Main)')?->toIso8601String())
        ->toBe('2026-11-18T10:00:00+00:00');
    Queue::assertNothingPushed();
});

it('does not record a green for a non-default branch', function () {
    postWebhook('workflow_job', workflowJobPayload(['conclusion' => 'success']));

    expect(CiDefaultBranchGreen::count())->toBe(0);
});

it('resolves a branch failures when the branch is deleted or its PR closes', function (string $event, array $payload, string $resolution) {
    $failure = CiJobFailure::factory()->create(['repo' => 'widgets-slug', 'branch' => 'feature/x']);
    $other = CiJobFailure::factory()->create(['repo' => 'widgets-slug', 'branch' => 'feature/y']);

    postWebhook($event, $payload);

    expect($failure->fresh()->resolution)->toBe($resolution)
        ->and($other->fresh()->resolved_at)->toBeNull();
})->with([
    'branch deleted' => ['delete', ['ref_type' => 'branch', 'ref' => 'feature/x', 'repository' => ['id' => 1, 'full_name' => 'acme/widgets']], 'branch_deleted'],
    'pr closed' => ['pull_request', ['action' => 'closed', 'pull_request' => ['html_url' => 'https://github.com/acme/widgets/pull/9', 'head' => ['ref' => 'feature/x']], 'repository' => ['id' => 1, 'full_name' => 'acme/widgets']], 'pr_closed'],
]);
