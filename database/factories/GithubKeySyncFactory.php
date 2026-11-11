<?php

namespace Database\Factories;

use App\Models\GithubKeySync;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubKeySync>
 */
class GithubKeySyncFactory extends Factory
{
    protected $model = GithubKeySync::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'github_id' => fake()->unique()->numberBetween(1000, 99999999),
            'github_login' => fake()->userName(),
            'status' => 'ok',
            'fetched_at' => now(),
            'checked_at' => now(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => ['user_id' => $user->id, 'github_id' => $user->github_id ?? fake()->unique()->numberBetween(1000, 99999999)]);
    }

    public function errored(): static
    {
        return $this->state(fn () => ['status' => 'error', 'stale_since' => now(), 'last_error' => 'GitHub returned 502']);
    }
}
