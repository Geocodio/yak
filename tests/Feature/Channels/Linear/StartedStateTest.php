<?php

use App\Models\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function fakeLinearGraphqlWithStates(array $nodes): void
{
    Http::fake(function ($request) use ($nodes) {
        if (str_contains($request->url(), 'api.linear.app/graphql') && str_contains($request['query'] ?? '', 'workflowStates')) {
            return Http::response(['data' => ['issue' => ['team' => ['states' => ['nodes' => $nodes]]]]]);
        }

        return Http::response(['data' => ['agentActivityCreate' => ['success' => true], 'issueUpdate' => ['success' => true]]]);
    });
}

function assertIssueMovedTo(?string $stateId): void
{
    if ($stateId === null) {
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.linear.app/graphql')
            && str_contains($request['query'] ?? '', 'issueUpdate'));

        return;
    }

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.linear.app/graphql')
        && str_contains($request['query'] ?? '', 'issueUpdate')
        && ($request['variables']['stateId'] ?? null) === $stateId);
}

it('moves the Linear issue to the configured state when started_state_id is set', function () {
    $secret = enableLinearChannel();
    config()->set('yak.channels.linear.started_state_id', 'started-state-uuid');
    linearConnection();
    Queue::fake();
    fakeLinearGraphqlWithStates([]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload(['issueId' => 'issue-started-uuid']), $secret)
        ->assertSuccessful();

    assertIssueMovedTo('started-state-uuid');

    // The env override skips team-state discovery entirely.
    Http::assertNotSent(fn ($request): bool => str_contains($request['query'] ?? '', 'workflowStates'));
});

it('auto-discovers the team started state when no started_state_id is configured', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    fakeLinearGraphqlWithStates([
        ['id' => 'state-todo', 'name' => 'Todo', 'type' => 'unstarted', 'position' => 1],
        ['id' => 'state-progress', 'name' => 'In Progress', 'type' => 'started', 'position' => 2],
    ]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload(['issueId' => 'issue-started-uuid']), $secret)
        ->assertSuccessful();

    assertIssueMovedTo('state-progress');
});

it('does not move the issue when the connection toggle is off', function () {
    $secret = enableLinearChannel();
    linearConnection()->update(['move_issues_to_started_state' => false]);
    Queue::fake();
    fakeLinearGraphqlWithStates([
        ['id' => 'state-progress', 'name' => 'In Progress', 'type' => 'started', 'position' => 1],
    ]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload(), $secret)->assertSuccessful();

    assertIssueMovedTo(null);
    Http::assertNotSent(fn ($request): bool => str_contains($request['query'] ?? '', 'workflowStates'));
});

it('still picks up the task when the team has no started-type state', function () {
    $secret = enableLinearChannel();
    linearConnection();
    Queue::fake();
    fakeLinearGraphqlWithStates([
        ['id' => 'state-done', 'name' => 'Done', 'type' => 'completed', 'position' => 1],
    ]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload(), $secret)->assertSuccessful();

    assertIssueMovedTo(null);
});
