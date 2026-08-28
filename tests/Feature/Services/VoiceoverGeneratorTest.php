<?php

use App\Models\Artifact;
use App\Models\VideoMetric;
use App\Models\YakTask;
use App\Services\VoiceoverGenerator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

function voiceoverScript(): array
{
    return [
        'version' => 3,
        'intro' => 'This is the intro line.',
        'outro' => 'This is the outro line.',
        'shots' => [
            ['id' => 'levels', 'say' => 'The levels shot.'],
            ['id' => 'zip', 'say' => 'The zip shot.'],
        ],
    ];
}

beforeEach(function () {
    Storage::fake('artifacts');
    config()->set('yak.video.elevenlabs.api_key', 'test-key');
});

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

it('returns null and makes no request when the api key is unset', function () {
    config()->set('yak.video.elevenlabs.api_key', null);
    Http::fake();
    $task = YakTask::factory()->create();

    expect(app(VoiceoverGenerator::class)->generate($task, voiceoverScript()))->toBeNull();

    Http::assertNothingSent();
    expect($task->artifacts()->role('voiceover')->count())->toBe(0);
});

it('generates one mp3 per line and returns files with durations', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response('ID3-fake-audio', 200)]);
    $task = YakTask::factory()->create();

    $result = app(VoiceoverGenerator::class)->generate($task, voiceoverScript());

    expect(array_keys($result))->toBe(['intro', 'levels', 'zip', 'outro'])
        ->and($result['intro']['file'])->toBe("{$task->id}/vo/intro.mp3")
        ->and($result['intro']['durationSeconds'])->toBeFloat();

    Storage::disk('artifacts')->assertExists("{$task->id}/vo/outro.mp3");

    expect($task->artifacts()->role('voiceover')->pluck('filename')->sort()->values()->all())
        ->toBe(['intro.mp3', 'levels.mp3', 'outro.mp3', 'zip.mp3'])
        ->and($task->artifacts()->role('voiceover')->first()->type)->toBe('file');
});

it('sends the configured voice, model and voice settings', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response('audio', 200)]);
    $task = YakTask::factory()->create();

    app(VoiceoverGenerator::class)->generate($task, ['intro' => 'Hello there.', 'shots' => []]);

    Http::assertSent(function (Request $request) {
        return str_starts_with($request->url(), 'https://api.elevenlabs.io/v1/text-to-speech/UgBBYS2sOqTuMpoF3BR0?output_format=mp3_44100_128')
            && $request->header('xi-api-key') === ['test-key']
            && $request['text'] === 'Hello there.'
            && $request['model_id'] === 'eleven_multilingual_v2'
            && $request['voice_settings']['stability'] === 0.5
            && $request['voice_settings']['similarity_boost'] === 0.75
            && $request['voice_settings']['style'] === 0.25
            && $request['voice_settings']['speed'] === 1.0;
    });
});

it('skips blank and missing lines', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response('audio', 200)]);
    $task = YakTask::factory()->create();

    $result = app(VoiceoverGenerator::class)->generate($task, [
        'intro' => '   ',
        'shots' => [['id' => 'only', 'say' => 'Just this one.'], ['id' => 'silent']],
    ]);

    expect(array_keys($result))->toBe(['only']);
    Http::assertSentCount(1);
});

it('reuses existing voiceover artifacts instead of regenerating them', function () {
    Http::fake(['api.elevenlabs.io/*' => Http::response('audio', 200)]);
    $task = YakTask::factory()->create();
    Storage::disk('artifacts')->put("{$task->id}/vo/intro.mp3", 'already here');
    Artifact::create([
        'yak_task_id' => $task->id,
        'type' => 'file',
        'role' => 'voiceover',
        'filename' => 'intro.mp3',
        'disk_path' => "{$task->id}/vo/intro.mp3",
        'size_bytes' => 12,
    ]);

    $generator = app(VoiceoverGenerator::class);
    $result = $generator->generate($task, ['intro' => 'This is the intro line.', 'outro' => 'Bye.', 'shots' => []]);

    expect(array_keys($result))->toBe(['intro', 'outro'])
        ->and($task->artifacts()->role('voiceover')->where('filename', 'intro.mp3')->count())->toBe(1)
        ->and($generator->charactersGenerated())->toBe(mb_strlen('Bye.'));

    Http::assertSentCount(1);
    expect(Storage::disk('artifacts')->get("{$task->id}/vo/intro.mp3"))->toBe('already here');
});

it('cleans up, warns and returns null when a request fails', function () {
    Log::spy();
    $task = YakTask::factory()->create();
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return $calls === 1 ? Http::response('audio', 200) : Http::response('nope', 500);
    });

    $result = app(VoiceoverGenerator::class)->generate($task, voiceoverScript());

    expect($result)->toBeNull()
        ->and($task->artifacts()->role('voiceover')->count())->toBe(0);

    Storage::disk('artifacts')->assertMissing("{$task->id}/vo/intro.mp3");
    expect(Cache::get(VoiceoverGenerator::FAILURE_CACHE_KEY))->not->toBeNull();
});

it('never throws when the http client blows up', function () {
    $task = YakTask::factory()->create();
    Http::fake(fn () => throw new ConnectionException('dns'));

    expect(app(VoiceoverGenerator::class)->generate($task, voiceoverScript()))->toBeNull()
        ->and(Cache::get(VoiceoverGenerator::FAILURE_CACHE_KEY))->not->toBeNull();
});

it('clears the failure marker after a successful run', function () {
    Cache::put(VoiceoverGenerator::FAILURE_CACHE_KEY, ['message' => 'old'], now()->addDay());
    Http::fake(['api.elevenlabs.io/*' => Http::response('audio', 200)]);
    $task = YakTask::factory()->create();

    app(VoiceoverGenerator::class)->generate($task, ['intro' => 'Fresh.', 'shots' => []]);

    expect(Cache::get(VoiceoverGenerator::FAILURE_CACHE_KEY))->toBeNull();
});
