<?php

namespace Database\Factories;

use App\Models\GitHubInstallationToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitHubInstallationToken>
 */
class GitHubInstallationTokenFactory extends Factory
{
    protected $model = GitHubInstallationToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'installation_id' => fake()->unique()->numberBetween(10000, 99999),
            'token' => 'ghs_' . fake()->sha256(),
            'expires_at' => now()->addHour(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subMinutes(5),
        ]);
    }
}
