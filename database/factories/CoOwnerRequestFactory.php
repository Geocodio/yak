<?php

namespace Database\Factories;

use App\Enums\CoOwnerRequestStatus;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoOwnerRequest>
 */
class CoOwnerRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => YakTask::factory()->awaitingCoOwner(),
            'root_task_id' => fn (array $attributes) => $attributes['task_id'],
            'repository_id' => Repository::factory(),
            'status' => CoOwnerRequestStatus::Pending,
            'profile_source' => 'file',
            'profile_commit_sha' => fake()->sha1(),
            'matched_areas' => [[
                'name' => 'API middleware and HTTP kernel',
                'risk' => 'high',
                'rationale' => 'Every request passes through it.',
                'paths' => ['app/Http/Middleware/**'],
            ]],
            'hold_reasons' => [],
            'changed_files' => [
                ['path' => 'app/Http/Middleware/Authenticate.php', 'previousPath' => null, 'status' => 'modified', 'additions' => 12, 'deletions' => 3, 'isHeld' => true],
                ['path' => 'tests/Feature/AuthenticateTest.php', 'previousPath' => null, 'status' => 'new', 'additions' => 30, 'deletions' => 0, 'isHeld' => false],
            ],
            'additions' => 42,
            'deletions' => 3,
            'file_count' => 2,
            'asked_user_ids' => [],
            'ask_attempts' => [],
            'direct_messages' => [],
            'held_at' => now(),
            'expires_at' => now()->addDays(7),
            'notified_areas' => [],
        ];
    }
}
