<?php

namespace App\Channels\Linear;

use App\Exceptions\LinearOAuthRefreshFailedException;
use App\Models\LinearOauthConnection;
use App\Services\HealthCheck\Channel\ChannelCheck;
use App\Services\HealthCheck\HealthAction;
use App\Services\HealthCheck\HealthResult;
use Illuminate\Support\Facades\Http;

class HealthCheck extends ChannelCheck
{
    public function __construct(private readonly OAuthService $oauthService) {}

    public function id(): string
    {
        return 'linear';
    }

    public function name(): string
    {
        return 'Linear';
    }

    public function run(): HealthResult
    {
        $connection = LinearOauthConnection::active();

        if ($connection === null) {
            return HealthResult::notConnected(
                'OAuth connection not set up yet',
                $this->connectAction('Connect Linear'),
            );
        }

        return $this->safely(function () use ($connection): HealthResult {
            try {
                $accessToken = $connection->freshAccessToken($this->oauthService);
            } catch (LinearOAuthRefreshFailedException $e) {
                return HealthResult::error(
                    'OAuth refresh failed — ' . $e->getMessage(),
                    $this->connectAction('Reconnect Linear'),
                );
            }

            $response = Http::withToken($accessToken)
                ->timeout(5)
                ->post('https://api.linear.app/graphql', [
                    'query' => 'query { viewer { id name } }',
                ]);

            if ($response->status() === 401 || $response->status() === 403) {
                return HealthResult::error(
                    "{$response->status()} — access token rejected",
                    $this->connectAction('Reconnect Linear'),
                );
            }

            $response->throw();

            /** @var array{data?: array{viewer?: array{id?: string, name?: string}}} $json */
            $json = (array) $response->json();

            $viewerId = $json['data']['viewer']['id'] ?? null;
            if (! $viewerId) {
                return HealthResult::error(
                    'GraphQL returned no viewer',
                    $this->connectAction('Reconnect Linear'),
                );
            }

            $viewerName = (string) ($json['data']['viewer']['name'] ?? 'unknown');
            $workspace = (string) ($connection->workspace_name ?? 'unknown workspace');

            return HealthResult::ok("Connected to {$workspace} as {$viewerName}");
        });
    }

    private function connectAction(string $label): HealthAction
    {
        return new HealthAction(label: $label, url: route('auth.linear.redirect'));
    }
}
