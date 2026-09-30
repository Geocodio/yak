<?php

namespace App\Channels\Linear;

use App\Exceptions\LinearOAuthRefreshFailedException;
use App\Models\LinearOauthConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Discovers which workflow states a Linear issue should move to while Yak
 * works on it. Workflow states are defined per team, so they are resolved
 * through the issue's team at the moment of the move rather than configured
 * as a single workspace-wide id.
 *
 * Linear workflow states have fixed `type`s (`started`, `completed`, ...)
 * but teams choose their own names and can have several `started` states.
 * The "working" state is therefore the leftmost `started` state, and the
 * "in review" state is the leftmost `started` state whose name contains
 * "review", the only portable signal available. Teams whose review state
 * is named differently set an explicit state id in configuration.
 */
class WorkflowStateResolver
{
    private const GRAPHQL_ENDPOINT = 'https://api.linear.app/graphql';

    /**
     * Return the id of the leftmost `started`-type workflow state of the
     * issue's team, or null when it cannot be determined. Failures are
     * logged and swallowed: moving the issue is a nice-to-have that must
     * never block task pickup.
     */
    public function forIssue(string $issueId): ?string
    {
        return $this->startedStates($issueId)->first()['id'] ?? null;
    }

    /**
     * Return the id of the leftmost `started`-type workflow state of the
     * issue's team whose name contains "review" (case-insensitive), or null
     * when there is none or the lookup fails.
     */
    public function inReviewForIssue(string $issueId): ?string
    {
        $review = $this->startedStates($issueId)
            ->first(fn (array $state): bool => str_contains(mb_strtolower((string) ($state['name'] ?? '')), 'review'));

        return $review['id'] ?? null;
    }

    /**
     * The issue team's `started`-type states, leftmost first.
     *
     * @return Collection<int, array{id: string, name?: string, type?: string, position?: int|float}>
     */
    private function startedStates(string $issueId): Collection
    {
        $accessToken = $this->resolveAccessToken();
        if ($accessToken === null || $issueId === '') {
            return collect();
        }

        try {
            $response = Http::withToken($accessToken)
                ->post(self::GRAPHQL_ENDPOINT, [
                    'query' => 'query($issueId: String!) { issue(id: $issueId) { team { states: workflowStates { nodes { id name type position } } } } }',
                    'variables' => ['issueId' => $issueId],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('WorkflowStateResolver: workflow state lookup failed', [
                'issue_id' => $issueId,
                'message' => $e->getMessage(),
            ]);

            return collect();
        }

        if (! $response->successful()) {
            Log::warning('WorkflowStateResolver: workflow state lookup failed', [
                'issue_id' => $issueId,
                'status' => $response->status(),
            ]);

            return collect();
        }

        /** @var array<int, array{id?: string, name?: string, type?: string, position?: int|float}> $nodes */
        $nodes = (array) $response->json('data.issue.team.states.nodes', []);

        /** @var Collection<int, array{id: string, name?: string, type?: string, position?: int|float}> */
        return collect($nodes)
            ->filter(fn (array $state): bool => ($state['type'] ?? null) === 'started' && ($state['id'] ?? '') !== '')
            ->sortBy(fn (array $state): float => (float) ($state['position'] ?? PHP_FLOAT_MAX))
            ->values();
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
            Log::warning('WorkflowStateResolver skipped: refresh failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
