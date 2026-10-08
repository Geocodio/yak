<?php

namespace Database\Factories;

use App\Models\TaskAttachment;
use App\Models\YakTask;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TaskAttachment>
 */
class TaskAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->slug(2) . '.png';

        return [
            'yak_task_id' => YakTask::factory(),
            'context' => TaskAttachment::CONTEXT_REQUEST,
            'disk_path' => 'attachments/' . Str::ulid() . '/' . $name,
            'original_name' => $name,
            'mime_type' => 'image/png',
            'size_bytes' => fake()->numberBetween(10_000, 2_000_000),
        ];
    }

    public function file(string $name = 'notes.txt', string $mimeType = 'text/plain'): static
    {
        return $this->state(fn (): array => [
            'disk_path' => 'attachments/' . Str::ulid() . '/' . $name,
            'original_name' => $name,
            'mime_type' => $mimeType,
        ]);
    }

    public function clarificationReply(): static
    {
        return $this->state(fn (): array => ['context' => TaskAttachment::CONTEXT_CLARIFICATION_REPLY]);
    }
}
