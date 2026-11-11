<?php

namespace Database\Factories;

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Models\Thread;
use App\Models\ThreadRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadRepository>
 */
class ThreadRepositoryFactory extends Factory
{
    protected $model = ThreadRepository::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'repo' => 'acme/widgets',
            'role' => RepositoryRole::Primary,
            'sandbox_path' => '/workspace',
            'setup_status' => RepositorySetupStatus::Ready,
        ];
    }

    public function role(RepositoryRole $role): static
    {
        return $this->state(['role' => $role]);
    }

    public function changes(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => RepositoryRole::Changes,
            'sandbox_path' => ThreadRepository::sandboxPathFor($attributes['repo'], RepositoryRole::Changes),
        ]);
    }

    public function context(): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => RepositoryRole::Context,
            'sandbox_path' => ThreadRepository::sandboxPathFor($attributes['repo'], RepositoryRole::Context),
        ]);
    }

    public function failed(string $error = 'setup.sh exited with 1'): static
    {
        return $this->state(['setup_status' => RepositorySetupStatus::Failed, 'setup_error' => $error]);
    }

    public function settingUp(): static
    {
        return $this->state(['setup_status' => RepositorySetupStatus::Setup, 'setup_started_at' => now()]);
    }

    public function withPullRequest(int $number = 1, string $state = 'open'): static
    {
        return $this->state(fn (array $attributes): array => [
            'pull_request_number' => $number,
            'pull_request_state' => $state,
            'pull_request_url' => "https://github.com/{$attributes['repo']}/pull/{$number}",
        ]);
    }
}
