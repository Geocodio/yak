<?php

namespace Database\Factories;

use App\Models\BranchDeployment;
use App\Models\DeploymentLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeploymentLog>
 */
class DeploymentLogFactory extends Factory
{
    protected $model = DeploymentLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_deployment_id' => BranchDeployment::factory(),
            'level' => 'info',
            'message' => fake()->sentence(),
            'metadata' => null,
        ];
    }

    public function info(): static
    {
        return $this->state(fn (): array => [
            'level' => 'info',
            'message' => fake()->sentence(),
        ]);
    }

    public function warning(): static
    {
        return $this->state(fn (): array => [
            'level' => 'warning',
            'message' => 'Warning: ' . fake()->sentence(),
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (): array => [
            'level' => 'error',
            'message' => 'Error: ' . fake()->sentence(),
            'metadata' => ['trace' => fake()->text(200)],
        ]);
    }
}
