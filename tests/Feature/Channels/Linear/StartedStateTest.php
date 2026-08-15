<?php

use App\Models\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('moves the Linear issue to the started state when started_state_id is configured', function () {
    $secret = enableLinearChannel();
    config()->set('yak.channels.linear.started_state_id', 'started-state-uuid');
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true], 'issueUpdate' => ['success' => true]]])]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload(['issueId' => 'issue-started-uuid']), $secret)
        ->assertSuccessful();

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'api.linear.app/graphql')) {
            return false;
        }

        if (! str_contains($request['query'], 'issueUpdate')) {
            return false;
        }

        return ($request['variables']['stateId'] ?? null) === 'started-state-uuid'
            && ($request['variables']['issueId'] ?? null) === 'issue-started-uuid';
    });
});

it('does not send a started-state issueUpdate when started_state_id is not configured', function () {
    $secret = enableLinearChannel();
    // started_state_id is intentionally absent from enableLinearChannel()
    linearConnection();
    Queue::fake();
    Http::fake(['*' => Http::response(['data' => ['agentActivityCreate' => ['success' => true]]])]);
    Repository::factory()->default()->create();

    postLinearWebhook(agentSessionCreatedPayload(), $secret)->assertSuccessful();

    Http::assertNotSent(function ($request): bool {
        if (! str_contains($request->url(), 'api.linear.app/graphql')) {
            return false;
        }

        return str_contains($request['query'], 'issueUpdate');
    });
});
