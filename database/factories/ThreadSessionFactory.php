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
            'parent_session_id' => null,
            'kind' => ThreadSessionKind::Main,
        ];
    }

    public function fork(string $parentSessionId): static
    {
        return $this->state([
            'kind' => ThreadSessionKind::Fork,
            'parent_session_id' => $parentSessionId,
        ]);
    }
}
