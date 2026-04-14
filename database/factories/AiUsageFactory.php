<?php

namespace Database\Factories;

use App\Models\AiUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsage>
 */
class AiUsageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'yak_task_id' => null,
            'agent_class' => 'App\\Ai\\Agents\\PersonalityAgent',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'invocation_id' => 'inv_' . $this->faker->uuid(),
            'prompt_tokens' => $this->faker->numberBetween(100, 5000),
            'completion_tokens' => $this->faker->numberBetween(50, 1000),
            'cache_write_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'reasoning_tokens' => 0,
            'cost_usd' => 0.0015,
        ];
    }
}
