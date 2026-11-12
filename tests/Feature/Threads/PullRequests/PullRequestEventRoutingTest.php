<?php

use App\Channels\GitHub\AppService;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\Threads\RetryRepositoryCiJob;
use App\Jobs\Threads\StartRepositoryTurnJob;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Providers\ChannelServiceProvider;
use App\Services\Threads\PullRequests\SiblingFeedbackGate;
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
    config()->set('yak.followup.github_review_triage_enabled', true);
    (new ChannelServiceProvider(app()))->boot();
    Queue::fake();
    Http::fake();

    Repository::factory()->create(['slug' => 'acme-api', 'github_full_name' => 'acme/api', 'ci_system' => 'github_actions']);
    Repository::factory()->create(['slug' => 'acme-web', 'github_full_name' => 'acme/web', 'ci_system' => 'github_actions']);
    Repository::factory()->create(['slug' => 'acme-docs', 'github_full_name' => 'acme/docs', 'ci_system' => 'github_actions']);

    $this->thread = Thread::factory()->create();
    $this->task = YakTask::factory()->awaitingCi()->create(['thread_id' => $this->thread->id, 'repo' => 'acme-api', 'branch_name' => 'yak/x', 'pr_url' => 'https://github.com/acme/api/pull/188']);
    $this->api = ThreadRepository::factory()->for($this->thread)->withPullRequest(188)->create(['repo' => 'acme-api', 'branch' => 'yak/x', 'pull_request_url' => 'https://github.com/acme/api/pull/188']);
    $this->web = ThreadRepository::factory()->for($this->thread)->changes()->withPullRequest(188)->create(['repo' => 'acme-web', 'branch' => 'yak/x', 'sandbox_path' => '/workspace/web', 'pull_request_url' => 'https://github.com/acme/web/pull/188']);
    $this->docs = ThreadRepository::factory()->for($this->thread)->context()->create(['repo' => 'acme-docs', 'branch' => 'yak/x']);
});

function permitSiblingFeedback(): void
{
    test()->mock(SiblingFeedbackGate::class)->shouldReceive('permits')->andReturn(true);
}

function sendGitHub(string $event, array $payload): TestResponse
{
    return test()->postJson('/webhooks/github', $payload, [
        'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'secret'),
        'X-GitHub-Event' => $event,
    ]);
}

function checkSuite(string $repo, string $conclusion = 'failure', int $number = 188): array
{
    return [
        'action' => 'completed',
        'check_suite' => ['head_branch' => 'yak/x', 'conclusion' => $conclusion, 'head_sha' => 'abc', 'head_repository' => ['full_name' => $repo], 'pull_requests' => [['number' => $number]]],
        'repository' => ['full_name' => $repo],
    ];
}

function issueComment(string $repo, string $body, string $type = 'User', int $number = 188): array
{
    return [
        'action' => 'created',
        'issue' => ['number' => $number, 'pull_request' => ['html_url' => "https://github.com/{$repo}/pull/{$number}"]],
        'comment' => ['id' => 5, 'body' => $body, 'user' => ['login' => 'sam', 'type' => $type]],
        'repository' => ['full_name' => $repo],
    ];
}

it('retries only the sibling repo when its CI fails', function () {
    sendGitHub('check_suite', checkSuite('acme/web'))->assertOk()->assertJson(['dispatched' => true]);

    Queue::assertPushed(RetryRepositoryCiJob::class, fn ($job) => $job->threadRepositoryId === $this->web->id);
    Queue::assertNotPushed(ProcessCIResultJob::class);
    expect($this->api->refresh()->ci_retry_count)->toBe(0);
});

it('does not retry a sibling repo for a fork check suite reusing the branch name', function () {
    $this->web->forceFill(['branch' => 'yak/x', 'last_pushed_sha' => 'pushed'])->save();
    $payload = checkSuite('acme/web');
    $payload['check_suite']['pull_requests'] = [];
    $payload['check_suite']['head_sha'] = 'forked';
    $payload['check_suite']['head_repository'] = ['full_name' => 'evil/web'];

    sendGitHub('check_suite', $payload)->assertOk();

    Queue::assertNotPushed(RetryRepositoryCiJob::class);
});

it('keeps primary pull request CI on the task path', function () {
    sendGitHub('check_suite', checkSuite('acme/api'))->assertOk();

    Queue::assertPushed(ProcessCIResultJob::class, fn ($job) => $job->task->is($this->task));
    Queue::assertNotPushed(RetryRepositoryCiJob::class);
});

it('starts a turn naming the repo for a sibling comment', function () {
    permitSiblingFeedback();
    config()->set('yak.followup.github_prefixes', '/yak');

    sendGitHub('issue_comment', issueComment('acme/web', '/yak rename the field'))->assertOk()->assertJson(['dispatched' => true]);

    Queue::assertPushed(StartRepositoryTurnJob::class, fn ($job) => $job->threadRepositoryId === $this->web->id
        && str_contains($job->prompt, 'acme/web#188') && str_contains($job->prompt, 'rename the field'));
});

it('starts a turn for a sibling review with feedback', function () {
    permitSiblingFeedback();
    sendGitHub('pull_request_review', [
        'action' => 'submitted',
        'review' => ['id' => 9, 'state' => 'changes_requested', 'body' => '/yak Please add a test', 'user' => ['login' => 'sam', 'type' => 'User']],
        'pull_request' => ['number' => 188, 'html_url' => 'https://github.com/acme/web/pull/188'],
        'repository' => ['full_name' => 'acme/web'],
    ])->assertOk();

    Queue::assertPushed(StartRepositoryTurnJob::class, fn ($job) => str_contains($job->prompt, 'Please add a test') && str_contains($job->prompt, 'acme/web#188'));
});

it('ignores sibling reviews without the prefix, including approvals and changes requested', function () {
    config()->set('yak.followup.github_prefixes', '/yak');

    foreach ([['approved', 'LGTM'], ['commented', 'nice'], ['changes_requested', 'Please add a test'], ['changes_requested', '']] as [$state, $body]) {
        sendGitHub('pull_request_review', [
            'action' => 'submitted',
            'review' => ['id' => 9, 'state' => $state, 'body' => $body, 'user' => ['login' => 'sam', 'type' => 'User']],
            'pull_request' => ['number' => 188, 'html_url' => 'https://github.com/acme/web/pull/188'],
            'repository' => ['full_name' => 'acme/web'],
        ])->assertOk();
    }

    Queue::assertNotPushed(StartRepositoryTurnJob::class);
});

it('ignores sibling reviews when review triage is disabled', function () {
    config()->set('yak.followup.github_review_triage_enabled', false);

    sendGitHub('pull_request_review', [
        'action' => 'submitted',
        'review' => ['id' => 9, 'state' => 'commented', 'body' => '/yak do it', 'user' => ['login' => 'sam', 'type' => 'User']],
        'pull_request' => ['number' => 188, 'html_url' => 'https://github.com/acme/web/pull/188'],
        'repository' => ['full_name' => 'acme/web'],
    ])->assertOk();

    Queue::assertNotPushed(StartRepositoryTurnJob::class);
});

it('ignores bot comments and comments without the prefix', function () {
    config()->set('yak.followup.github_prefixes', '/yak');

    sendGitHub('issue_comment', issueComment('acme/web', '/yak do it', 'Bot'))->assertOk();
    sendGitHub('issue_comment', issueComment('acme/web', 'looks good'))->assertOk();

    Queue::assertNotPushed(StartRepositoryTurnJob::class);
});

it('declines a comment on a merged sibling pull request', function () {
    permitSiblingFeedback();
    config()->set('yak.followup.github_prefixes', '/yak');
    config()->set('yak.channels.github.installation_id', 1);
    $this->web->forceFill(['pull_request_state' => 'merged'])->save();
    $service = Mockery::mock(AppService::class)->makePartial();
    $service->shouldReceive('appBotLogin')->andReturn('yak[bot]');
    $service->shouldReceive('addReaction')->once();
    $service->shouldReceive('commentOnPullRequest')->once()->withArgs(fn ($installation, $repo, $number, $text) => $repo === 'acme/web' && $number === 188 && str_contains($text, 'already merged'));
    app()->instance(AppService::class, $service);

    sendGitHub('issue_comment', issueComment('acme/web', '/yak more'))->assertOk()->assertJson(['skipped' => 'pr not open']);

    Queue::assertNotPushed(StartRepositoryTurnJob::class);
});

it('updates only the closed repo row', function () {
    sendGitHub('pull_request', [
        'action' => 'closed',
        'pull_request' => ['html_url' => 'https://github.com/acme/web/pull/188', 'merged' => true, 'number' => 188],
        'repository' => ['full_name' => 'acme/web'],
    ])->assertOk();

    expect($this->web->refresh()->pull_request_state)->toBe('merged')->and($this->api->refresh()->pull_request_state)->toBe('open');
});

it('ignores events on a context repo', function () {
    config()->set('yak.followup.github_prefixes', '/yak');
    $this->docs->forceFill(['pull_request_number' => 3, 'pull_request_url' => 'https://github.com/acme/docs/pull/3'])->save();

    sendGitHub('check_suite', checkSuite('acme/docs', 'failure', 3))->assertOk()->assertJson(['skipped' => 'context repo']);
    sendGitHub('issue_comment', issueComment('acme/docs', '/yak hi', 'User', 3))->assertOk()->assertJson(['skipped' => 'context repo']);

    Queue::assertNothingPushed();
});
