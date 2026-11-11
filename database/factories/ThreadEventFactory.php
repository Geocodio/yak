<?php

namespace Database\Factories;

use App\Models\Thread;
use App\Models\ThreadEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadEvent>
 */
class ThreadEventFactory extends Factory
{
    protected $model = ThreadEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'sequence' => fake()->unique()->numberBetween(1, 1_000_000),
            'type' => 'entry',
            'payload' => ['role' => 'user', 'text' => fake()->sentence(), 'kind' => 'prompt'],
            'created_at' => now(),
        ];
    }
}
