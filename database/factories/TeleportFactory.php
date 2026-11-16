<?php

namespace Database\Factories;

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Models\Device;
use App\Models\Teleport;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Teleport>
 */
class TeleportFactory extends Factory
{
    protected $model = Teleport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => Thread::factory(),
            'device_id' => fn (): int => Device::factory()->create()->id,
            'user_id' => fn (array $attributes): int => (int) Device::query()->whereKey($attributes['device_id'])->value('user_id'),
            'direction' => TeleportDirection::Up,
            'state' => TeleportState::Open,
        ];
    }

    public function up(): static
    {
        return $this->state(['direction' => TeleportDirection::Up]);
    }

    public function down(): static
    {
        return $this->state(['direction' => TeleportDirection::Down]);
    }

    public function inState(TeleportState $state): static
    {
        return $this->state(['state' => $state]);
    }
}
