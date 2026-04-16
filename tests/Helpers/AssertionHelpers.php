<?php

use Illuminate\Support\Facades\Http;

/**
 * Assert that a Slack thread reply was sent.
 *
 * @param  string|null  $channel  Expected channel ID (null to skip check)
 * @param  string|null  $threadTs  Expected thread timestamp (null to skip check)
 * @param  string|null  $textContains  Expected text/blocks substring (null to skip check).
 *                                     Searches the `text` fallback field AND the JSON-encoded
 *                                     `blocks` payload, so it matches either a plain-text
 *                                     fallback or a Block Kit button URL / section content.
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

        if ($textContains !== null) {
            $haystack = (string) ($request['text'] ?? '');
            if (isset($request['blocks'])) {
                $haystack .= ' ' . json_encode($request['blocks'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (! str_contains($haystack, $textContains)) {
                return false;
            }
        }

        return true;
    });
}

/**
 * Assert that a Linear agent activity was posted via the
 * agentActivityCreate GraphQL mutation.
 *
 * @param  string|null  $bodyContains  Expected activity body substring (null to skip check)
 */
function assertLinearActivity(?string $bodyContains = null): void
{
    Http::assertSent(function ($request) use ($bodyContains) {
        if (! str_contains($request->url(), 'api.linear.app/graphql')) {
            return false;
        }

        if (! str_contains($request['query'], 'agentActivityCreate')) {
            return false;
        }

        if ($bodyContains !== null && ! str_contains($request['variables']['input']['content']['body'] ?? '', $bodyContains)) {
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

        if ($stateId !== null) {
            // Check both inline query and variables for the stateId
            $inQuery = str_contains($request['query'], $stateId);
            $inVariables = ($request['variables']['stateId'] ?? '') === $stateId;

            if (! $inQuery && ! $inVariables) {
                return false;
            }
        }

        return true;
    });
}
