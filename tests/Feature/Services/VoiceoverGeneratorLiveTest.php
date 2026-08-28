<?php

use App\Models\YakTask;
use App\Services\VoiceoverGenerator;
use Illuminate\Support\Facades\Storage;

it('generates two real mp3s with probeable durations', function () {
    $task = YakTask::factory()->create();

    $result = app(VoiceoverGenerator::class)->generate($task, [
        'intro' => 'Yak opened a pull request for this change.',
        'outro' => 'Ready for review.',
        'shots' => [],
    ]);

    expect($result)->toBeArray()
        ->and(array_keys($result))->toBe(['intro', 'outro'])
        ->and($result['intro']['durationSeconds'])->toBeGreaterThan(0.5)
        ->and($result['outro']['durationSeconds'])->toBeGreaterThan(0.3);

    foreach ($result as $line) {
        Storage::disk('artifacts')->assertExists($line['file']);
        Storage::disk('artifacts')->delete($line['file']);
    }
})->skip(
    fn () => blank(env('ELEVENLABS_API_KEY')),
    'ELEVENLABS_API_KEY is not set; skipping the live ElevenLabs call.'
);
