<?php

namespace Database\Factories;

use App\Models\ApprovalPreview;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalPreview>
 */
class ApprovalPreviewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 9999);

        return [
            'repository_id' => Repository::factory(),
            'pr_number' => $number,
            'head_sha' => fake()->sha1(),
            'title' => fake()->sentence(4),
            'author' => fake()->userName(),
            'url' => "https://github.com/acme/api/pull/{$number}",
            'status' => 'pending',
        ];
    }

    public function done(): static
    {
        return $this->state(fn (): array => [
            'status' => 'done',
            'result' => ['holds' => [], 'changeKind' => 'presentation_or_internal', 'hasTestChange' => false, 'waiting' => false, 'classification' => null],
        ]);
    }
}
