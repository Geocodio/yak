<?php

namespace Database\Factories;

use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadShare>
 */
class ThreadShareFactory extends Factory
{
    protected $model = ThreadShare::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'user_id' => User::factory(),
        ];
    }
}
