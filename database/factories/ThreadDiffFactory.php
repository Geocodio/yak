<?php

namespace Database\Factories;

use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadDiffFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadDiff>
 */
class ThreadDiffFactory extends Factory
{
    protected $model = ThreadDiff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'base_sha' => fake()->sha1(),
            'head_sha' => fake()->sha1(),
            'files_count' => 0,
            'additions' => 0,
            'deletions' => 0,
            'is_truncated' => false,
            'captured_at' => now(),
        ];
    }

    /**
     * Adds changed files and keeps the diff totals in step with them.
     */
    public function withFiles(int $count = 2): static
    {
        return $this->afterCreating(function (ThreadDiff $diff) use ($count): void {
            $files = ThreadDiffFile::factory()->count($count)->create(['thread_diff_id' => $diff->id]);

            $diff->update([
                'files_count' => $files->count(),
                'additions' => (int) $files->sum('additions'),
                'deletions' => (int) $files->sum('deletions'),
            ]);
        });
    }
}
