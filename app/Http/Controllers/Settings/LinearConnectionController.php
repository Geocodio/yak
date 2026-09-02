<?php

namespace App\Http\Controllers\Settings;

use App\Channels\Linear\OAuthService as LinearOAuthService;
use App\Http\Controllers\Controller;
use App\Models\LinearOauthConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class LinearConnectionController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Settings/Linear', [
            'linear' => fn () => $this->presentConnection(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'moveIssuesToStartedState' => ['required', 'boolean'],
        ]);

        $this->connection()?->update([
            'move_issues_to_started_state' => $validated['moveIssuesToStartedState'],
        ]);

        return redirect()->route('settings.linear');
    }

    public function disconnect(LinearOAuthService $service): RedirectResponse
    {
        $connection = $this->connection();

        if ($connection === null) {
            return redirect()->route('settings.linear');
        }

        try {
            $service->revoke($connection);
        } catch (Throwable) {
            // Even if Linear's revoke endpoint fails we still want to
            // clear the local row so the user can reconnect.
            $connection->markDisconnected();
        }

        $connection->delete();

        return redirect()->route('settings.linear')->with('success', 'Linear disconnected.');
    }

    /** @return array<string, mixed> */
    private function presentConnection(): array
    {
        $connection = $this->connection();
        $isConnected = $connection !== null && $connection->disconnected_at === null;

        return [
            'oauthConfigured' => $this->oauthConfigured(),
            'isConnected' => $isConnected,
            'workspaceName' => $connection?->workspace_name,
            'workspaceId' => $connection?->workspace_id,
            'actor' => $connection?->actor,
            'scopes' => $connection?->scopes,
            'expiresAt' => $connection?->expires_at?->toIso8601String(),
            'disconnectedAt' => $connection?->disconnected_at?->toIso8601String(),
            'moveIssuesToStartedState' => $connection === null || $connection->move_issues_to_started_state,
        ];
    }

    private function connection(): ?LinearOauthConnection
    {
        return LinearOauthConnection::query()->latest('id')->first();
    }

    private function oauthConfigured(): bool
    {
        return (bool) config('yak.channels.linear.oauth_client_id')
            && (bool) config('yak.channels.linear.oauth_client_secret')
            && (bool) config('yak.channels.linear.oauth_redirect_uri');
    }
}
