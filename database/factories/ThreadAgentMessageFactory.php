<?php

namespace Database\Factories;

use App\Models\ThreadAgent;
use App\Models\ThreadAgentMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThreadAgentMessage>
 */
class ThreadAgentMessageFactory extends Factory
{
    protected $model = ThreadAgentMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['thread_agent_id' => ThreadAgent::factory(), 'user_id' => null, 'text' => fake()->sentence(), 'forwarded_at' => null];
    }
}
