<?php

namespace Database\Factories;

use App\Models\PrReview;
use App\Models\PrReviewComment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrReviewComment>
 */
class PrReviewCommentFactory extends Factory
{
    protected $model = PrReviewComment::class;

    public function definition(): array
    {
        return [
            'pr_review_id' => PrReview::factory(),
            'github_comment_id' => fake()->unique()->numberBetween(1_000_000, 999_999_999),
            'file_path' => 'app/Services/Example.php',
            'line_number' => fake()->numberBetween(1, 500),
            'body' => fake()->sentence(),
            'category' => fake()->randomElement(['Simplicity', 'Test Quality', 'Performance']),
            'severity' => fake()->randomElement(['must_fix', 'should_fix', 'consider']),
            'is_suggestion' => false,
            'thumbs_up' => 0,
            'thumbs_down' => 0,
        ];
    }
}
