<?php

namespace Database\Factories;

use App\Enums\HeldEventKind;
use App\Models\HeldThreadEvent;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HeldThreadEvent>
 */
class HeldThreadEventFactory extends Factory
{
    protected $model = HeldThreadEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'kind' => HeldEventKind::CiFailed,
            'title' => 'CI failed on the pull request',
            'summary' => fake()->sentence(),
            'payload' => [],
        ];
    }
}
