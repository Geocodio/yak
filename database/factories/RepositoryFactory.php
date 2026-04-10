<?php

namespace Database\Factories;

use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    protected $model = Repository::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'is_default' => false,
            'is_active' => true,
            'setup_status' => 'ready',
            'path' => '/home/yak/repos/'.$slug,
            'default_branch' => 'main',
            'ci_system' => fake()->randomElement(['github_actions', 'drone']),
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => [
            'is_default' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }

    public function withAuth(): static
    {
        return $this->state(fn (): array => [
            'setup_status' => 'ready',
            'notes' => 'Configured with deploy key authentication.',
        ]);
    }

    public function withSentry(): static
    {
        return $this->state(fn (): array => [
            'sentry_project' => fake()->slug(2),
        ]);
    }
}
