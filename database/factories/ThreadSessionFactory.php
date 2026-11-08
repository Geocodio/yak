<?php

namespace Database\Factories;

use App\Enums\ThreadSessionKind;
use App\Models\Thread;
use App\Models\ThreadSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadSession>
 */
class ThreadSessionFactory extends Factory
{
    protected $model = ThreadSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'session_id' => fake()->uuid(),
            'kind' => ThreadSessionKind::Main,
        ];
    }
}
