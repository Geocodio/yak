<?php

namespace App\Services;

use App\Models\Artifact;
use App\Models\YakTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Optional narration for a v3 walkthrough (spec §6). One MP3 per spoken
 * line, written as `voiceover` artifacts the renderer already knows how
 * to pick up. A voiceover problem never fails a render: every failure
 * path cleans up after itself and returns null, and the cut goes out
 * captions-only.
 */
class VoiceoverGenerator
{
    public const string FAILURE_CACHE_KEY = 'voiceover:last_failure';

    private const string ENDPOINT = 'https://api.elevenlabs.io/v1/text-to-speech';

    private int $charactersGenerated = 0;

    public function __construct(private readonly VideoRenderer $renderer) {}

    /**
     * @param  array<string, mixed>  $script  decoded script.json
     * @return array<string, array{file: string, durationSeconds: float}>|null
     */
    public function generate(YakTask $task, array $script): ?array
    {
        $this->charactersGenerated = 0;

        $key = config('yak.video.elevenlabs.api_key');

        if (! is_string($key) || trim($key) === '') {
            Log::channel('yak')->info('VoiceoverGenerator: skipped, no ELEVENLABS_API_KEY', ['task_id' => $task->id]);

            return null;
        }

        $lines = $this->lines($script);

        if ($lines === []) {
            return null;
        }

        $disk = Storage::disk('artifacts');
        $url = self::ENDPOINT . '/' . (string) config('yak.video.elevenlabs.voice_id') . '?output_format=mp3_44100_128';
        $model = (string) config('yak.video.elevenlabs.model_id');

        /** @var list<string> $createdPaths */
        $createdPaths = [];
        /** @var list<int> $createdArtifactIds */
        $createdArtifactIds = [];
        /** @var array<string, array{file: string, durationSeconds: float}> $result */
        $result = [];

        try {
            foreach ($lines as $id => $text) {
                $diskPath = "{$task->id}/vo/{$id}.mp3";

                if ($this->existingArtifact($task, "{$id}.mp3") !== null && $disk->exists($diskPath)) {
                    $result[$id] = [
                        'file' => $diskPath,
                        'durationSeconds' => $this->duration($diskPath),
                    ];

                    continue;
                }

                $response = Http::withHeaders([
                    'xi-api-key' => $key,
                    'Accept' => 'audio/mpeg',
                ])->timeout(120)->post($url, [
                    'text' => $text,
                    'model_id' => $model,
                    'voice_settings' => [
                        'stability' => 0.5,
                        'similarity_boost' => 0.75,
                        'style' => 0.25,
                        'speed' => 1.0,
                    ],
                ]);

                if ($response->failed()) {
                    $this->rollBack($createdPaths, $createdArtifactIds);
                    $this->recordFailure($task, "ElevenLabs returned HTTP {$response->status()} for line '{$id}'");

                    return null;
                }

                $body = $response->body();
                $disk->put($diskPath, $body);
                $createdPaths[] = $diskPath;

                $artifact = Artifact::create([
                    'yak_task_id' => $task->id,
                    'type' => 'file',
                    'role' => 'voiceover',
                    'filename' => "{$id}.mp3",
                    'disk_path' => $diskPath,
                    'size_bytes' => strlen($body),
                ]);
                $createdArtifactIds[] = (int) $artifact->id;

                $this->charactersGenerated += mb_strlen($text);

                $result[$id] = [
                    'file' => $diskPath,
                    'durationSeconds' => $this->duration($diskPath),
                ];
            }
        } catch (Throwable $e) {
            $this->rollBack($createdPaths, $createdArtifactIds);
            $this->charactersGenerated = 0;
            $this->recordFailure($task, $e->getMessage());

            return null;
        }

        Cache::forget(self::FAILURE_CACHE_KEY);

        return $result;
    }

    /**
     * Characters sent to ElevenLabs by the last `generate()` call. Zero when
     * the run was skipped, failed, or served entirely from existing files.
     */
    public function charactersGenerated(): int
    {
        return $this->charactersGenerated;
    }

    /**
     * Spoken lines in playback order: the intro, each shot's `say`, then the
     * outro. Anything missing, non-string, or blank once trimmed is dropped.
     *
     * @param  array<string, mixed>  $script
     * @return array<string, string> line id => text
     */
    private function lines(array $script): array
    {
        $lines = [];

        $intro = $this->text($script['intro'] ?? null);

        if ($intro !== null) {
            $lines['intro'] = $intro;
        }

        $shots = $script['shots'] ?? [];

        if (is_array($shots)) {
            foreach ($shots as $shot) {
                if (! is_array($shot)) {
                    continue;
                }

                $id = $shot['id'] ?? null;
                $say = $this->text($shot['say'] ?? null);

                if (! is_string($id) || trim($id) === '' || $say === null) {
                    continue;
                }

                $lines[trim($id)] = $say;
            }
        }

        $outro = $this->text($script['outro'] ?? null);

        if ($outro !== null) {
            $lines['outro'] = $outro;
        }

        return $lines;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function existingArtifact(YakTask $task, string $filename): ?Artifact
    {
        return Artifact::query()
            ->where('yak_task_id', $task->id)
            ->role('voiceover')
            ->where('filename', $filename)
            ->first();
    }

    /**
     * ffprobe is best-effort: a missing binary or an unreadable file leaves
     * the line at zero rather than failing the whole voiceover.
     */
    private function duration(string $diskPath): float
    {
        try {
            return $this->renderer->probeDurationSeconds(Storage::disk('artifacts')->path($diskPath)) ?? 0.0;
        } catch (Throwable) {
            return 0.0;
        }
    }

    /**
     * Remove only what this call created — artifacts reused from an earlier
     * run stay exactly where they were.
     *
     * @param  list<string>  $paths
     * @param  list<int>  $artifactIds
     */
    private function rollBack(array $paths, array $artifactIds): void
    {
        try {
            foreach ($paths as $path) {
                Storage::disk('artifacts')->delete($path);
            }

            if ($artifactIds !== []) {
                Artifact::query()->whereIn('id', $artifactIds)->delete();
            }
        } catch (Throwable) {
            // Cleanup is best-effort; a stuck file must never fail a render.
        }
    }

    private function recordFailure(YakTask $task, string $reason): void
    {
        $this->charactersGenerated = 0;

        try {
            Cache::put(self::FAILURE_CACHE_KEY, [
                'message' => $reason,
                'at' => now()->toIso8601String(),
            ], now()->addDay());
        } catch (Throwable) {
            // Never let bookkeeping take down the caller.
        }

        try {
            Log::channel('yak')->warning('VoiceoverGenerator: generation failed', [
                'task_id' => $task->id,
                'reason' => $reason,
            ]);
        } catch (Throwable) {
            // Never let bookkeeping take down the caller.
        }
    }
}
