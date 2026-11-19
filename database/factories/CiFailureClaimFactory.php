<?php

namespace Database\Factories;

use App\Models\CiFailureClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CiFailureClaim>
 */
class CiFailureClaimFactory extends Factory
{
    protected $model = CiFailureClaim::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repo' => 'acme/widgets',
            'workflow_path' => '.github/workflows/ci.yml',
            'job_name' => 'Test (Main)',
            'test_class' => null,
            'yak_task_id' => null,
            'skipped_pr_url' => null,
            'created_at' => now(),
        ];
    }
}
