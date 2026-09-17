<?php

namespace Database\Factories;

use App\Models\TelemetryEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelemetryEvent>
 */
class TelemetryEventFactory extends Factory
{
    protected $model = TelemetryEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'occurred_at' => now(),
            'name' => 'feature.used',
            'repo' => 'acme/widgets',
            'source' => 'slack',
            'yak_task_id' => null,
            'task_run_id' => null,
            'subject_type' => null,
            'subject_id' => null,
            'duration_ms' => null,
            'value' => null,
            'properties' => ['feature' => 'follow_up'],
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => ['name' => $name]);
    }
}
