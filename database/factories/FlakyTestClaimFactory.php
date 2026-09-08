<?php

namespace Database\Factories;

use App\Models\FlakyTestClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlakyTestClaim>
 */
class FlakyTestClaimFactory extends Factory
{
    protected $model = FlakyTestClaim::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repo' => 'acme/widgets',
            'test_class' => 'Tests\\Feature\\UserTest',
            'yak_task_id' => null,
            'skipped_pr_url' => null,
            'created_at' => now(),
        ];
    }

    public function skippedFor(string $prUrl): self
    {
        return $this->state(fn (): array => ['skipped_pr_url' => $prUrl]);
    }
}
