<?php

namespace App\Services;

use App\Exceptions\LinearOAuthRefreshFailedException;
use App\Models\LinearOauthConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LinearOAuthService
{
    private const AUTHORIZE_URL = 'https://linear.app/oauth/authorize';

    private const TOKEN_URL = 'https://api.linear.app/oauth/token';

    private const REVOKE_URL = 'https://api.linear.app/oauth/revoke';

    private const GRAPHQL_URL = 'https://api.linear.app/graphql';

    /**
     * Build the URL users are redirected to in order to grant Yak access
     * to their Linear workspace.
     */
    public function authorizeUrl(string $state): string
    {
        $params = [
            'client_id' => (string) config('yak.channels.linear.oauth_client_id'),
            'redirect_uri' => (string) config('yak.channels.linear.oauth_redirect_uri'),
            'response_type' => 'code',
            'scope' => $this->scopes(),
            'state' => $state,
            'actor' => 'app',
            'prompt' => 'consent',
        ];

        $this->assertScopesCompatibleWithActorApp($params['scope']);

        return self::AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange an authorization code for access + refresh tokens, then
     * look up the workspace so we have a friendly name to display.
     */
    public function exchangeCode(string $code, ?int $createdByUserId = null): LinearOauthConnection
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) config('yak.channels.linear.oauth_redirect_uri'),
            'client_id' => (string) config('yak.channels.linear.oauth_client_id'),
            'client_secret' => (string) config('yak.channels.linear.oauth_client_secret'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Linear token exchange failed: ' . $response->body());
        }

        /** @var array{access_token?: string, refresh_token?: string, expires_in?: int, scope?: string, token_type?: string} $json */
        $json = $response->json();

        $accessToken = (string) ($json['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Linear returned no access_token.');
        }

        $viewer = $this->fetchViewer($accessToken);

        $scopes = isset($json['scope'])
            ? explode(' ', $json['scope'])
            : [];

        $attributes = [
            'access_token' => $accessToken,
            'refresh_token' => $json['refresh_token'] ?? null,
            'expires_at' => Carbon::now()->addSeconds((int) ($json['expires_in'] ?? 0)),
            'scopes' => $scopes,
            'actor' => 'app',
            'app_user_id' => $viewer['app_user_id'] ?? null,
            'installer_user_id' => $viewer['user_id'] ?? null,
            'workspace_name' => $viewer['workspace_name'],
            'workspace_url_key' => $viewer['workspace_url_key'] ?? null,
            'created_by_user_id' => $createdByUserId,
            'disconnected_at' => null,
        ];

        return LinearOauthConnection::updateOrCreate(
            ['workspace_id' => $viewer['workspace_id']],
            $attributes,
        );
    }

    /**
     * Refresh the access token on an existing connection.
     *
     * @throws LinearOAuthRefreshFailedException
     */
    public function refresh(LinearOauthConnection $connection): void
    {
        $refreshToken = (string) $connection->refresh_token;
        if ($refreshToken === '') {
            $connection->markDisconnected();
            throw new LinearOAuthRefreshFailedException('Linear connection has no refresh token.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => (string) config('yak.channels.linear.oauth_client_id'),
            'client_secret' => (string) config('yak.channels.linear.oauth_client_secret'),
        ]);

        if (! $response->successful()) {
            $body = (string) $response->body();
            Log::warning('Linear OAuth refresh failed', [
                'workspace_id' => $connection->workspace_id,
                'status' => $response->status(),
                'body' => substr($body, 0, 200),
            ]);
            $connection->markDisconnected();
            throw new LinearOAuthRefreshFailedException("Linear refresh failed ({$response->status()}).");
        }

        /** @var array{access_token?: string, refresh_token?: string, expires_in?: int} $json */
        $json = $response->json();

        $connection->forceFill([
            'access_token' => (string) ($json['access_token'] ?? ''),
            'refresh_token' => $json['refresh_token'] ?? $refreshToken,
            'expires_at' => Carbon::now()->addSeconds((int) ($json['expires_in'] ?? 0)),
        ])->save();
    }

    /**
     * Revoke the current access token on Linear's side and mark the
     * local row disconnected.
     */
    public function revoke(LinearOauthConnection $connection): void
    {
        Http::withToken((string) $connection->access_token)
            ->post(self::REVOKE_URL);

        $connection->markDisconnected();
    }

    /**
     * Look up the authenticated viewer + its organization using a
     * just-issued access token.
     *
     * @return array{workspace_id: string, workspace_name: string, workspace_url_key: ?string, app_user_id: ?string, user_id: ?string}
     */
    public function fetchViewer(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->post(self::GRAPHQL_URL, [
            'query' => 'query { viewer { id name } organization { id name urlKey } }',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Linear viewer lookup failed: ' . $response->body());
        }

        /** @var array{data?: array{viewer?: array{id?: string, name?: string}, organization?: array{id?: string, name?: string, urlKey?: string}}} $json */
        $json = $response->json();

        $data = $json['data'] ?? [];
        $viewer = $data['viewer'] ?? [];
        $organization = $data['organization'] ?? [];

        $workspaceId = (string) ($organization['id'] ?? '');
        if ($workspaceId === '') {
            throw new RuntimeException('Linear viewer lookup returned no organization id.');
        }

        return [
            'workspace_id' => $workspaceId,
            'workspace_name' => (string) ($organization['name'] ?? 'Linear Workspace'),
            'workspace_url_key' => $organization['urlKey'] ?? null,
            'app_user_id' => null,
            'user_id' => $viewer['id'] ?? null,
        ];
    }

    /**
     * Return scopes as a space-separated string.
     */
    private function scopes(): string
    {
        $raw = (string) config('yak.channels.linear.oauth_scopes', 'read,write');

        return implode(' ', array_filter(array_map('trim', explode(',', $raw))));
    }

    private function assertScopesCompatibleWithActorApp(string $scopes): void
    {
        $list = explode(' ', $scopes);

        if (in_array('admin', $list, true)) {
            throw new RuntimeException('Linear does not allow the `admin` scope when actor=app. Remove it from yak.channels.linear.oauth_scopes.');
        }
    }
}
