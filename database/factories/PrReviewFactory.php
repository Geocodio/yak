<?php

namespace Database\Factories;

use App\Models\PrReview;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrReview>
 */
class PrReviewFactory extends Factory
{
    protected $model = PrReview::class;

    public function definition(): array
    {
        return [
            'yak_task_id' => YakTask::factory(),
            'repo' => 'geocodio/api',
            'pr_number' => fake()->numberBetween(1, 9999),
            'pr_url' => fn (array $a) => "https://github.com/{$a['repo']}/pull/{$a['pr_number']}",
            'github_review_id' => fake()->numberBetween(1000000, 9999999),
            'commit_sha_reviewed' => fake()->sha1(),
            'review_scope' => 'full',
            'summary' => fake()->sentence(),
            'verdict' => 'Approve with suggestions',
            'submitted_at' => now(),
        ];
    }

    public function incremental(): self
    {
        return $this->state(fn () => [
            'review_scope' => 'incremental',
            'incremental_base_sha' => fake()->sha1(),
        ]);
    }

    public function dismissed(): self
    {
        return $this->state(fn () => ['dismissed_at' => now()]);
    }
}
