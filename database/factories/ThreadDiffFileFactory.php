<?php

namespace Database\Factories;

use App\Enums\DiffFileStatus;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadDiffFile>
 */
class ThreadDiffFileFactory extends Factory
{
    protected $model = ThreadDiffFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_diff_id' => ThreadDiff::factory(),
            'path' => 'src/' . fake()->unique()->slug(2) . '.php',
            'old_path' => null,
            'status' => DiffFileStatus::Modified,
            'additions' => 3,
            'deletions' => 1,
            'is_binary' => false,
            'is_too_large' => false,
            'patch' => "@@ -1 +1,3 @@\n-old\n+new\n+newer\n+newest\n",
        ];
    }

    public function binary(): static
    {
        return $this->state(['additions' => null, 'deletions' => null, 'is_binary' => true, 'patch' => null]);
    }

    public function tooLarge(): static
    {
        return $this->state(['is_too_large' => true, 'patch' => null]);
    }
}
