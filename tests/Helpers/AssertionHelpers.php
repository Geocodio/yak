<?php

use Illuminate\Support\Facades\Http;

/**
 * Assert that a Slack thread reply was sent.
 *
 * @param  string|null  $channel  Expected channel ID (null to skip check)
 * @param  string|null  $threadTs  Expected thread timestamp (null to skip check)
 * @param  string|null  $textContains  Expected text substring (null to skip check)
 */
function assertSlackThreadReply(?string $channel = null, ?string $threadTs = null, ?string $textContains = null): void
{
    Http::assertSent(function ($request) use ($channel, $threadTs, $textContains) {
        if (! str_contains($request->url(), 'slack.com/api/chat.postMessage')) {
            return false;
        }

        if ($channel !== null && $request['channel'] !== $channel) {
            return false;
        }

        if ($threadTs !== null && $request['thread_ts'] !== $threadTs) {
            return false;
        }

        if ($textContains !== null && ! str_contains($request['text'], $textContains)) {
            return false;
        }

        return true;
    });
}

/**
 * Assert that a Linear comment was posted via GraphQL.
 *
 * @param  string|null  $bodyContains  Expected comment body substring (null to skip check)
 */
function assertLinearComment(?string $bodyContains = null): void
{
    Http::assertSent(function ($request) use ($bodyContains) {
        if (! str_contains($request->url(), 'api.linear.app/graphql')) {
            return false;
        }

        if (! str_contains($request['query'], 'commentCreate')) {
            return false;
        }

        if ($bodyContains !== null && ! str_contains($request['variables']['body'] ?? '', $bodyContains)) {
            return false;
        }

        return true;
    });
}

/**
 * Assert that a Linear issue state was updated via GraphQL.
 *
 * @param  string|null  $stateId  Expected state ID (null to skip check)
 */
function assertLinearStateUpdate(?string $stateId = null): void
{
    Http::assertSent(function ($request) use ($stateId) {
        if (! str_contains($request->url(), 'api.linear.app/graphql')) {
            return false;
        }

        if (! str_contains($request['query'], 'issueUpdate')) {
            return false;
        }

        if ($stateId !== null && ! str_contains($request['query'], $stateId)) {
            return false;
        }

        return true;
    });
}
