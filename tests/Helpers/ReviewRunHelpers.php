<?php

use App\Enums\TaskMode;
use App\Models\YakTask;

/**
 * A review task of one pull request, with the context a review run records.
 *
 * @param  array<string, mixed>  $attributes
 */
function seedReviewRun(array $attributes = [], int $prNumber = 50, string $repo = 'geocodio/api'): YakTask
{
    return YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'repo' => $repo,
        'pr_url' => "https://github.com/{$repo}/pull/{$prNumber}",
        'branch_name' => 'feature/billing',
        'description' => "Review PR #{$prNumber}: Bill from cents",
        'context' => json_encode(['pr_number' => $prNumber, 'head_sha' => 'abc1234def', 'author' => 'imliam', 'review_scope' => 'full']),
        ...$attributes,
    ]);
}
