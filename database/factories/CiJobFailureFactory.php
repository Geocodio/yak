<?php

namespace Database\Factories;

use App\Models\CiJobFailure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CiJobFailure>
 */
class CiJobFailureFactory extends Factory
{
    protected $model = CiJobFailure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repo' => 'acme/widgets',
            'workflow_path' => '.github/workflows/ci.yml',
            'workflow_name' => 'CI',
            'job_name' => 'Test (Main)',
            'failed_step' => 'Run tests',
            'test_names' => null,
            'branch' => 'feature/' . fake()->unique()->slug(2),
            'head_sha' => fake()->sha1(),
            'job_id' => fake()->unique()->numberBetween(1, 2_000_000_000),
            'run_id' => fake()->numberBetween(1, 2_000_000_000),
            'job_url' => 'https://github.com/acme/widgets/actions/runs/1/job/1',
            'actor_login' => 'octocat',
            'failed_at' => now(),
            'resolved_at' => null,
            'resolution' => null,
        ];
    }
}
