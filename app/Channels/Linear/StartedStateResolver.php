<?php

namespace App\Channels\Linear;

use App\Exceptions\LinearOAuthRefreshFailedException;
use App\Models\LinearOauthConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Discovers which workflow state a Linear issue should move to when Yak
 * starts working on it. Workflow states are defined per team, so the
 * state is resolved through the issue's team at pickup time rather than
 * configured as a single workspace-wide id.
 */
class StartedStateResolver
{
    private const GRAPHQL_ENDPOINT = 'https://api.linear.app/graphql';

    /**
     * Return the id of the leftmost `started`-type workflow state of the
     * issue's team, or null when it cannot be determined. Failures are
     * logged and swallowed — moving the issue is a nice-to-have that must
     * never block task pickup.
     */
    public function forIssue(string $issueId): ?string
    {
        $accessToken = $this->resolveAccessToken();
        if ($accessToken === null || $issueId === '') {
            return null;
        }

        $response = Http::withToken($accessToken)
            ->post(self::GRAPHQL_ENDPOINT, [
                'query' => 'query($issueId: String!) { issue(id: $issueId) { team { states: workflowStates { nodes { id name type position } } } } }',
                'variables' => ['issueId' => $issueId],
            ]);

        if (! $response->successful()) {
            Log::warning('StartedStateResolver: workflow state lookup failed', [
                'issue_id' => $issueId,
                'status' => $response->status(),
            ]);

            return null;
        }

        /** @var array<int, array{id?: string, type?: string, position?: int|float}> $nodes */
        $nodes = (array) $response->json('data.issue.team.states.nodes', []);

        $started = collect($nodes)
            ->filter(fn (array $state): bool => ($state['type'] ?? null) === 'started' && ($state['id'] ?? '') !== '')
            ->sortBy(fn (array $state): float => (float) ($state['position'] ?? PHP_FLOAT_MAX))
            ->first();

        return $started['id'] ?? null;
    }

    private function resolveAccessToken(): ?string
    {
        $connection = LinearOauthConnection::active();
        if ($connection === null) {
            return null;
        }

        try {
            return $connection->freshAccessToken(app(OAuthService::class));
        } catch (LinearOAuthRefreshFailedException $e) {
            Log::warning('StartedStateResolver skipped: refresh failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
