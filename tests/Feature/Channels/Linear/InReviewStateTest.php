<?php

use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\Enums\NotificationType;
use App\Jobs\ProcessCIResultJob;
use App\Models\GitHubInstallationToken;
use App\Models\LinearOauthConnection;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 99999);
    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);
});

function fakeLinearTeamWithReviewState(): void
{
    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'api.github.com') && str_ends_with($url, '/pulls')) {
            return Http::response(['number' => 7, 'html_url' => 'https://github.com/org/my-repo/pull/7']);
        }

        if (str_contains($url, 'api.linear.app/graphql') && str_contains($request['query'] ?? '', 'workflowStates')) {
            return Http::response(['data' => ['issue' => ['team' => ['states' => ['nodes' => [
                ['id' => 'state-progress', 'name' => 'In Progress', 'type' => 'started', 'position' => 1],
                ['id' => 'state-review', 'name' => 'In Review', 'type' => 'started', 'position' => 2],
            ]]]]]]);
        }

        return Http::response(['data' => ['success' => true]]);
    });
}

function openLinearPullRequest(): YakTask
{
    Process::fake([
        '*git diff --name-only *' => Process::result(''),
        '*git diff --stat *' => Process::result(' 1 file changed, 5 insertions(+)'),
        '*git checkout *' => Process::result(''),
        '*git branch -D *' => Process::result(''),
    ]);

    Repository::factory()->create(['slug' => 'org/my-repo', 'path' => '/home/yak/repos/my-repo']);

    $task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/my-repo',
        'branch_name' => 'yak/LIN-100',
        'source' => 'linear',
        'external_id' => 'issue-uuid-100',
        'linear_agent_session_id' => 'session-review',
        'attempts' => 1,
    ]);

    (new ProcessCIResultJob($task, true))->handle();

    return $task->refresh();
}

function assertMovedToState(?string $stateId): void
{
    $moved = fn ($request): bool => str_contains($request['query'] ?? '', 'issueUpdate')
        && ($request['variables']['stateId'] ?? null) === $stateId;

    if ($stateId === null) {
        Http::assertNotSent(fn ($request): bool => str_contains($request['query'] ?? '', 'issueUpdate'));

        return;
    }

    Http::assertSent($moved);
}

it('moves the issue to the configured in-review state when a pull request opens', function () {
    fakeLinearTeamWithReviewState();
    LinearOauthConnection::factory()->create(['move_issues_to_started_state' => false]);
    config()->set('yak.channels.linear.in_review_state_id', 'configured-review');

    openLinearPullRequest();

    assertMovedToState('configured-review');
    Http::assertNotSent(fn ($request): bool => str_contains($request['query'] ?? '', 'workflowStates'));
});

it('resolves the team review state when none is configured and the toggle is on', function () {
    fakeLinearTeamWithReviewState();
    LinearOauthConnection::factory()->create(['move_issues_to_started_state' => true]);
    config()->set('yak.channels.linear.in_review_state_id', null);

    openLinearPullRequest();

    assertMovedToState('state-review');
    Http::assertSent(fn ($request): bool => ($request['variables']['issueId'] ?? null) === 'issue-uuid-100'
        && str_contains($request['query'] ?? '', 'workflowStates'));
});

it('leaves the issue alone when nothing is configured and the toggle is off', function () {
    fakeLinearTeamWithReviewState();
    LinearOauthConnection::factory()->create(['move_issues_to_started_state' => false]);
    config()->set('yak.channels.linear.in_review_state_id', null);

    openLinearPullRequest();

    assertMovedToState(null);
    Http::assertNotSent(fn ($request): bool => str_contains($request['query'] ?? '', 'workflowStates'));
});

it('still finishes the task when the review state lookup fails', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'api.github.com') && str_ends_with($request->url(), '/pulls')) {
            return Http::response(['number' => 7, 'html_url' => 'https://github.com/org/my-repo/pull/7']);
        }

        if (str_contains($request['query'] ?? '', 'workflowStates')) {
            return Http::response(['errors' => [['message' => 'boom']]], 500);
        }

        return Http::response(['data' => ['success' => true]]);
    });
    LinearOauthConnection::factory()->create();
    config()->set('yak.channels.linear.in_review_state_id', null);

    $task = openLinearPullRequest();

    expect($task->status->value)->toBe('success');
    assertMovedToState(null);
});

it('does not move a task with a pull request to done on a result notice', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();
    config()->set('yak.channels.linear.done_state_id', 'done-state');

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-uuid-200',
        'linear_agent_session_id' => 'session-pr',
        'pr_url' => 'https://github.com/org/my-repo/pull/9',
    ]);

    (new LinearNotificationDriver)->send($task, NotificationType::Result, 'All good');

    assertMovedToState(null);
});

it('still moves a task without a pull request to done on a result notice', function () {
    Http::fake(['*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();
    config()->set('yak.channels.linear.done_state_id', 'done-state');

    $task = YakTask::factory()->create([
        'source' => 'linear',
        'external_id' => 'issue-uuid-201',
        'linear_agent_session_id' => 'session-answered',
        'pr_url' => null,
    ]);

    (new LinearNotificationDriver)->send($task, NotificationType::Result, 'Answered');

    assertMovedToState('done-state');
});
