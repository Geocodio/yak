<?php

namespace Database\Factories;

use App\Enums\StackThreadMessageKind;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StackThreadMessage>
 */
class StackThreadMessageFactory extends Factory
{
    protected $model = StackThreadMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'root_thread_id' => Thread::factory(),
            'from_thread_id' => fn (array $attributes): int => Thread::factory()->child(Thread::withoutGlobalScopes()->whereKey($attributes['root_thread_id'])->firstOrFail())->create()->id,
            'to_thread_id' => fn (array $attributes): int => $attributes['root_thread_id'],
            'kind' => StackThreadMessageKind::Message,
            'text' => fake()->sentence(),
        ];
    }

    public function queued(): static
    {
        return $this->state(['queued_at' => now()]);
    }

    public function delivered(): static
    {
        return $this->state(['delivered_at' => now()]);
    }
}
