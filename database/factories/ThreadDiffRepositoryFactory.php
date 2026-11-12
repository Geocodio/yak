<?php

namespace Database\Factories;

use App\Enums\RepositoryRole;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadDiffRepository>
 */
class ThreadDiffRepositoryFactory extends Factory
{
    protected $model = ThreadDiffRepository::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_diff_id' => ThreadDiff::factory(),
            'repo' => 'acme/lib',
            'role' => RepositoryRole::Changes,
            'base_sha' => fake()->sha1(),
            'head_sha' => fake()->sha1(),
            'files_count' => 0,
            'additions' => 0,
            'deletions' => 0,
            'is_throwaway' => false,
        ];
    }
}
