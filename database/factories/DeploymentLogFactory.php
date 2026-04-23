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
}
