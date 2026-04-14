<?php

namespace App\Models;

use App\Exceptions\LinearOAuthRefreshFailedException;
use App\Services\LinearOAuthService;
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
