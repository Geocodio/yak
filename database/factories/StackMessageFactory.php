<?php

namespace Database\Factories;

use App\Enums\StackMessageKind;
use App\Models\StackMessage;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StackMessage>
 */
class StackMessageFactory extends Factory
{
    protected $model = StackMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'kind' => StackMessageKind::Note,
            'text' => fake()->sentence(),
        ];
    }
}
