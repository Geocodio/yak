<?php

namespace Database\Factories;

use App\Models\Thread;
use App\Models\ThreadFileEdit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadFileEdit>
 */
class ThreadFileEditFactory extends Factory
{
    protected $model = ThreadFileEdit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'user_id' => null,
            'path' => '/workspace/widgets/' . fake()->word() . '.ts',
            'content_sha256' => hash('sha256', fake()->sentence()),
        ];
    }
}
