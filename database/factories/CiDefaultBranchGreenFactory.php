<?php

namespace Database\Factories;

use App\Models\CiDefaultBranchGreen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CiDefaultBranchGreen>
 */
class CiDefaultBranchGreenFactory extends Factory
{
    protected $model = CiDefaultBranchGreen::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repo' => 'acme/widgets',
            'workflow_name' => 'CI',
            'job_name' => 'Test (Main)',
            'completed_at' => now(),
        ];
    }
}
