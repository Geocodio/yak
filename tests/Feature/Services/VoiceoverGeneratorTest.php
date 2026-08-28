<?php

use App\Models\VideoMetric;
use App\Models\YakTask;

it('defaults the elevenlabs voice and model and leaves the key unset', function () {
    expect(config('yak.video.elevenlabs.voice_id'))->toBe('UgBBYS2sOqTuMpoF3BR0')
        ->and(config('yak.video.elevenlabs.model_id'))->toBe('eleven_multilingual_v2');
});

it('stores tts characters on a video metric', function () {
    $task = YakTask::factory()->create();

    $metric = VideoMetric::create([
        'yak_task_id' => $task->id,
        'status' => VideoMetric::STATUS_RENDERED,
        'render_ms' => 1000,
        'tts_characters' => 1234,
    ]);

    expect($metric->fresh()->tts_characters)->toBe(1234);
});
