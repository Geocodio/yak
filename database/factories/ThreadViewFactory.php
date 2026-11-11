<?php

namespace Database\Factories;

use App\Models\Thread;
use App\Models\ThreadView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadView>
 */
class ThreadViewFactory extends Factory
{
    protected $model = ThreadView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'thread_id' => Thread::factory(),
            'last_seen_at' => now(),
        ];
    }
}
