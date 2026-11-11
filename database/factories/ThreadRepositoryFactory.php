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
}
