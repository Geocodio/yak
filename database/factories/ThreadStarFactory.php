<?php

namespace Database\Factories;

use App\Models\Thread;
use App\Models\ThreadStar;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadStar>
 */
class ThreadStarFactory extends Factory
{
    protected $model = ThreadStar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'thread_id' => Thread::factory(),
        ];
    }
}
