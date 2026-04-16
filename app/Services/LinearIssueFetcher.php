<?php

namespace App\Services;

use App\Exceptions\LinearOAuthRefreshFailedException;
use App\Models\LinearOauthConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Small GraphQL client for reading Linear issue metadata that
 * `AgentSessionEvent.created` webhook payloads don't include —
 * currently just labels. Kept separate from `LinearNotificationDriver`
 * (which is write-only) so the concerns don't drift.
 */
class LinearIssueFetcher
{
    private const GRAPHQL_ENDPOINT = 'https://api.linear.app/graphql';

    private const TIMEOUT_SECONDS = 3;

    /**
     * Return the lowercased label names for a Linear issue, or null
     * when we can't reach Linear (no active connection, OAuth refresh
     * failed, network error, etc.). Callers should treat null as "we
     * don't know" and fall back to whatever the webhook payload
     * already told us.
     *
     * @return list<string>|null
     */
    public function labelNames(string $identifier): ?array
    {
        $accessToken = $this->resolveAccessToken();
        if ($accessToken === null || $identifier === '') {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::GRAPHQL_ENDPOINT, [
                    'query' => 'query($id: String!) { issue(id: $id) { labels { nodes { name } } } }',
                    'variables' => ['id' => $identifier],
                ]);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('LinearIssueFetcher: label query failed', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        /** @var array<int, array{name?: string}>|null $nodes */
        $nodes = $response->json('data.issue.labels.nodes');
        if (! is_array($nodes)) {
            return null;
        }

        $names = [];
        foreach ($nodes as $node) {
            $name = is_array($node) ? ($node['name'] ?? null) : null;
            if (is_string($name) && $name !== '') {
                $names[] = strtolower($name);
            }
        }

        return $names;
    }

    public function hasLabel(string $identifier, string $labelName): bool
    {
        $names = $this->labelNames($identifier);
        if ($names === null) {
            return false;
        }

        return in_array(strtolower($labelName), $names, true);
    }

    private function resolveAccessToken(): ?string
    {
        $connection = LinearOauthConnection::active();
        if ($connection === null) {
            return null;
        }

        try {
            return $connection->freshAccessToken(app(LinearOAuthService::class));
        } catch (LinearOAuthRefreshFailedException $e) {
            Log::channel('yak')->warning('LinearIssueFetcher: OAuth refresh failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
