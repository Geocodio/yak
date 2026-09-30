<?php

use App\Channels\Linear\WorkflowStateResolver;
use App\Models\LinearOauthConnection;
use Illuminate\Support\Facades\Http;

function fakeTeamStates(array $nodes): void
{
    Http::fake([
        'api.linear.app/graphql' => Http::response([
            'data' => ['issue' => ['team' => ['states' => ['nodes' => $nodes]]]],
        ]),
    ]);
}

it('resolves the leftmost started-type state for the issue team', function () {
    LinearOauthConnection::factory()->create();
    fakeTeamStates([
        ['id' => 'state-todo', 'name' => 'Todo', 'type' => 'unstarted', 'position' => 1],
        ['id' => 'state-review', 'name' => 'In Review', 'type' => 'started', 'position' => 3],
        ['id' => 'state-progress', 'name' => 'In Progress', 'type' => 'started', 'position' => 2],
        ['id' => 'state-done', 'name' => 'Done', 'type' => 'completed', 'position' => 4],
    ]);

    expect(app(WorkflowStateResolver::class)->forIssue('issue-uuid-001'))->toBe('state-progress');

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'api.linear.app/graphql')
            && str_contains($request['query'], 'workflowStates')
            && ($request['variables']['issueId'] ?? null) === 'issue-uuid-001';
    });
});

it('returns null when the team has no started-type state', function () {
    LinearOauthConnection::factory()->create();
    fakeTeamStates([
        ['id' => 'state-todo', 'name' => 'Todo', 'type' => 'unstarted', 'position' => 1],
        ['id' => 'state-done', 'name' => 'Done', 'type' => 'completed', 'position' => 2],
    ]);

    expect(app(WorkflowStateResolver::class)->forIssue('issue-uuid-001'))->toBeNull();
});

it('returns null when the Linear API errors', function () {
    LinearOauthConnection::factory()->create();
    Http::fake(['api.linear.app/graphql' => Http::response(['errors' => [['message' => 'boom']]], 500)]);

    expect(app(WorkflowStateResolver::class)->forIssue('issue-uuid-001'))->toBeNull();
});

it('returns null when no Linear connection is active', function () {
    Http::fake();

    expect(app(WorkflowStateResolver::class)->forIssue('issue-uuid-001'))->toBeNull();

    Http::assertNothingSent();
});

it('resolves the leftmost started-type state whose name contains review', function () {
    LinearOauthConnection::factory()->create();
    fakeTeamStates([
        ['id' => 'state-progress', 'name' => 'In Progress', 'type' => 'started', 'position' => 2],
        ['id' => 'state-release', 'name' => 'In release branch', 'type' => 'started', 'position' => 4],
        ['id' => 'state-code-review', 'name' => 'Code REVIEW', 'type' => 'started', 'position' => 5],
        ['id' => 'state-review', 'name' => 'In Review', 'type' => 'started', 'position' => 3],
        ['id' => 'state-backlog-review', 'name' => 'Review backlog', 'type' => 'unstarted', 'position' => 1],
    ]);

    expect(app(WorkflowStateResolver::class)->inReviewForIssue('issue-uuid-001'))->toBe('state-review');
});

it('returns null for the review state when no started state mentions review', function () {
    LinearOauthConnection::factory()->create();
    fakeTeamStates([
        ['id' => 'state-progress', 'name' => 'In Progress', 'type' => 'started', 'position' => 2],
        ['id' => 'state-backlog-review', 'name' => 'Review backlog', 'type' => 'unstarted', 'position' => 1],
    ]);

    expect(app(WorkflowStateResolver::class)->inReviewForIssue('issue-uuid-001'))->toBeNull();
});

it('keeps resolving the started state when review states exist', function () {
    LinearOauthConnection::factory()->create();
    fakeTeamStates([
        ['id' => 'state-review', 'name' => 'In Review', 'type' => 'started', 'position' => 1],
        ['id' => 'state-progress', 'name' => 'In Progress', 'type' => 'started', 'position' => 2],
    ]);

    expect(app(WorkflowStateResolver::class)->forIssue('issue-uuid-001'))->toBe('state-review');
});
