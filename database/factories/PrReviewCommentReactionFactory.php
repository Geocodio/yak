<?php

namespace Database\Factories;

use App\Models\PrReviewComment;
use App\Models\PrReviewCommentReaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrReviewCommentReaction>
 */
class PrReviewCommentReactionFactory extends Factory
{
    protected $model = PrReviewCommentReaction::class;

    public function definition(): array
    {
        return [
            'pr_review_comment_id' => PrReviewComment::factory(),
            'github_reaction_id' => fake()->unique()->numberBetween(1_000_000, 999_999_999),
            'github_user_login' => fake()->userName(),
            'github_user_id' => fake()->numberBetween(1, 1_000_000),
            'content' => fake()->randomElement(['+1', '-1']),
            'reacted_at' => now(),
        ];
    }
}
