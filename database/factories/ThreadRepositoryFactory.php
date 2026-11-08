<?php

namespace Database\Factories;

use App\Models\Thread;
use App\Models\ThreadRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadRepository>
 */
class ThreadRepositoryFactory extends Factory
{
    protected $model = ThreadRepository::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'repo' => fake()->slug(2),
            'role' => 'primary',
            'sandbox_path' => '/workspace',
        ];
    }
}
