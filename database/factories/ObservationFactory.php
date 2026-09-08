<?php

namespace Database\Factories;

use App\Models\Observation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Observation>
 */
class ObservationFactory extends Factory
{
    protected $model = Observation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repo' => 'acme/widgets',
            'source' => 'flaky-test',
            'kind' => 'flaky_test.task_created',
            'outcome' => Observation::OUTCOME_ACTED,
            'summary' => fake()->sentence(),
            'subject' => 'Tests\\Feature\\UserTest',
            'reference_url' => null,
            'yak_task_id' => null,
            'metadata' => null,
            'created_at' => now(),
        ];
    }

    public function declined(): self
    {
        return $this->state(fn (): array => [
            'kind' => 'flaky_test.existing_pr',
            'outcome' => Observation::OUTCOME_DECLINED,
        ]);
    }
}
