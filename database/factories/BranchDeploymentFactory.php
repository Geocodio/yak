<?php

namespace Database\Factories;

use App\Enums\DeploymentStatus;
use App\Models\BranchDeployment;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchDeployment>
 */
class BranchDeploymentFactory extends Factory
{
    protected $model = BranchDeployment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branch = fake()->slug(2);
        $repoSlug = fake()->slug(1);

        return [
            'repository_id' => Repository::factory(),
            'branch_name' => $branch,
            'hostname' => "{$repoSlug}-{$branch}.yak.example.com",
            'container_name' => 'deploy-' . fake()->unique()->numberBetween(1000, 999999),
            'template_version' => 1,
            'status' => DeploymentStatus::Pending,
            'current_commit_sha' => null,
            'dirty' => false,
            'last_accessed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => DeploymentStatus::Pending,
        ]);
    }

    public function starting(): static
    {
        return $this->state(fn (): array => [
            'status' => DeploymentStatus::Starting,
            'current_commit_sha' => fake()->sha1(),
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => DeploymentStatus::Running,
            'current_commit_sha' => fake()->sha1(),
            'last_accessed_at' => now(),
        ]);
    }

    public function hibernated(): static
    {
        return $this->state(fn (): array => [
            'status' => DeploymentStatus::Hibernated,
            'current_commit_sha' => fake()->sha1(),
            'last_accessed_at' => now()->subHour(),
        ]);
    }

    public function destroyed(): static
    {
        return $this->state(fn (): array => [
            'status' => DeploymentStatus::Destroyed,
        ]);
    }

    public function failed(string $reason = 'test failure'): static
    {
        return $this->state(fn () => [
            'status' => DeploymentStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }
}
