<?php

namespace App\Models;

use App\Channels\Linear\OAuthService as LinearOAuthService;
use App\Exceptions\LinearOAuthRefreshFailedException;
use Database\Factories\LinearOauthConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class LinearOauthConnection extends Model
{
    /** @use HasFactory<LinearOauthConnectionFactory> */
    use HasFactory;

    protected $table = 'linear_oauth_connections';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'scopes' => 'json',
            'disconnected_at' => 'datetime',
        ];
    }

    /**
     * Return the single active connection, or null if Linear is not yet
     * connected (or the stored connection was invalidated).
     */
    public static function active(): ?self
    {
        return self::query()->whereNull('disconnected_at')->latest('id')->first();
    }

    /**
     * Return the active connection for a specific Linear workspace, or null.
     */
    public static function activeForWorkspace(string $workspaceId): ?self
    {
        return self::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('disconnected_at')
            ->latest('id')
            ->first();
    }

    /**
     * The actor id of the Yak OAuth app inside this workspace. Stored at
     * OAuth callback time by querying `viewer { id }` with an actor=app
     * token, which resolves to the app's bot user — the same id Linear
     * reports as `assignee.id` when a user assigns an issue to Yak.
     *
     * Historically tracked in the `installer_user_id` column; the name
     * is kept to avoid a migration but the semantics are the app actor.
     */
    public function yakActorId(): ?string
    {
        return $this->installer_user_id;
    }

    public function isExpired(int $skewSeconds = 60): bool
    {
        /** @var Carbon $expiresAt */
        $expiresAt = $this->expires_at;

        return $expiresAt->subSeconds($skewSeconds)->isPast();
    }

    /**
     * Return a valid access token, refreshing first if necessary.
     *
     * @throws LinearOAuthRefreshFailedException
     */
    public function freshAccessToken(LinearOAuthService $service): string
    {
        if ($this->isExpired()) {
            $service->refresh($this);
        }

        return (string) $this->access_token;
    }

    public function markDisconnected(): void
    {
        $this->forceFill(['disconnected_at' => now()])->save();
    }
}
