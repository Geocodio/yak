<?php

namespace Database\Factories;

use App\Models\RiskProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiskProfile>
 */
class RiskProfileFactory extends Factory
{
    protected $model = RiskProfile::class;

    public function definition(): array
    {
        $profile = [
            'schema_version' => 1, 'repo' => 'acme/api', 'source_sha' => fake()->sha1(),
            'areas' => [], 'unknowns' => [],
        ];
        $profile['version'] = hash('sha256', json_encode([
            $profile['schema_version'], $profile['repo'], $profile['source_sha'],
            $profile['areas'], $profile['unknowns'],
        ]));

        return [
            'repo' => $profile['repo'],
            'version' => $profile['version'],
            'profile' => $profile,
        ];
    }

    public function approved(): self
    {
        return $this->state(fn (): array => [
            'approved_by' => fake()->name(),
            'approved_at' => now(),
        ]);
    }
}
