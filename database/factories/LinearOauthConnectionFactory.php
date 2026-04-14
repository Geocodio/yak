<?php

namespace Database\Factories;

use App\Models\LinearOauthConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LinearOauthConnection>
 */
class LinearOauthConnectionFactory extends Factory
{
    protected $model = LinearOauthConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => (string) Str::uuid(),
            'workspace_name' => 'Acme',
            'workspace_url_key' => 'acme',
            'access_token' => 'lin_oauth_access_' . Str::random(24),
            'refresh_token' => 'lin_oauth_refresh_' . Str::random(24),
            'expires_at' => now()->addHours(23),
            'scopes' => ['read', 'write'],
            'actor' => 'app',
            'app_user_id' => 'app-user-' . Str::random(8),
            'installer_user_id' => 'user-' . Str::random(8),
            'created_by_user_id' => null,
            'disconnected_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinutes(5),
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (): array => [
            'disconnected_at' => now(),
        ]);
    }
}
