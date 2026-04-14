<?php

namespace App\Livewire\Settings;

use App\Models\LinearOauthConnection;
use App\Services\LinearOAuthService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Linear connection')]
class LinearConnection extends Component
{
    #[Computed]
    public function connection(): ?LinearOauthConnection
    {
        return LinearOauthConnection::query()->latest('id')->first();
    }

    #[Computed]
    public function isConnected(): bool
    {
        $connection = $this->connection();

        return $connection !== null && $connection->disconnected_at === null;
    }

    #[Computed]
    public function oauthConfigured(): bool
    {
        return (bool) config('yak.channels.linear.oauth_client_id')
            && (bool) config('yak.channels.linear.oauth_client_secret')
            && (bool) config('yak.channels.linear.oauth_redirect_uri');
    }

    public function disconnect(): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        try {
            app(LinearOAuthService::class)->revoke($connection);
        } catch (\Throwable) {
            // Even if Linear's revoke endpoint fails we still want to
            // clear the local row so the user can reconnect.
            $connection->markDisconnected();
        }

        $connection->delete();

        unset($this->connection, $this->isConnected);

        session()->flash('linear_oauth_success', 'Linear disconnected.');
    }
}
