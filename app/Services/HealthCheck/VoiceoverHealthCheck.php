<?php

namespace App\Services\HealthCheck;

use App\Models\Artifact;
use App\Services\VoiceoverGenerator;
use Illuminate\Support\Facades\Cache;

/**
 * Surfaces VoiceoverGenerator failures on the health page. A voiceover
 * failure never fails a render (VoiceoverGenerator falls back to
 * captions-only), so this row is the only place a failed generation
 * becomes visible.
 */
class VoiceoverHealthCheck implements HealthCheck
{
    public function id(): string
    {
        return 'voiceover';
    }

    public function name(): string
    {
        return 'Voiceover';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        $key = config('yak.video.elevenlabs.api_key');

        if (! is_string($key) || trim($key) === '') {
            return HealthResult::ok('Off (no ELEVENLABS_API_KEY)');
        }

        /** @var array{message: string, at: string}|null $failure */
        $failure = Cache::get(VoiceoverGenerator::FAILURE_CACHE_KEY);

        if ($failure !== null) {
            return HealthResult::error('Last generation failed: ' . mb_substr($failure['message'], 0, 120));
        }

        $count = Artifact::query()->where('role', 'voiceover')->where('created_at', '>=', now()->subDay())->count();

        return HealthResult::ok("On · {$count} lines generated (24h)");
    }
}
