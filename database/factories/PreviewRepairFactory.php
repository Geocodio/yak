<?php

namespace Database\Factories;

use App\Enums\PreviewRepairVerdict;
use App\Models\PreviewRepair;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PreviewRepair>
 */
class PreviewRepairFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'fingerprint' => hash('sha256', fake()->uuid()),
            'phase' => 'refresh',
            'error_excerpt' => 'ERROR: failed to read dockerfile: open Dockerfile: no such file or directory',
            'verdict' => PreviewRepairVerdict::Pending,
            'first_failed_at' => now(),
            'last_failed_at' => now(),
        ];
    }

    public function recipeWithOpenPullRequest(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verdict' => PreviewRepairVerdict::Recipe,
            'cause' => 'The recipe builds a Dockerfile the repository no longer has.',
            'yak_task_id' => YakTask::factory()->success()->state([
                'source' => 'preview-repair',
                'repo' => is_int($attributes['repository_id']) ? Repository::find($attributes['repository_id'])?->slug : null,
            ]),
        ]);
    }

    public function branchVerdict(): static
    {
        return $this->state(fn (): array => [
            'verdict' => PreviewRepairVerdict::Branch,
            'cause' => 'The branch adds a migration that fails.',
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'resolved_at' => now(),
        ]);
    }
}
