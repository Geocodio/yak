<?php

namespace App\Services\HealthCheck;

use App\Models\VideoMetric;

/**
 * Surfaces RenderVideoJob failures on the health page. Notification on
 * failure is RenderVideoJob::failed()'s job; this row only displays, so
 * the two never double-fire.
 */
class RenderHealthCheck implements HealthCheck
{
    public function id(): string
    {
        return 'video-render';
    }

    public function name(): string
    {
        return 'Video Render';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        $since = now()->subDay();

        $rendered = VideoMetric::query()->where('status', VideoMetric::STATUS_RENDERED)->where('created_at', '>=', $since)->count();
        $failed = VideoMetric::query()->where('status', VideoMetric::STATUS_FAILED)->where('created_at', '>=', $since);
        $failedCount = (clone $failed)->count();

        if ($rendered === 0 && $failedCount === 0) {
            return HealthResult::ok('No renders in the last 24h');
        }

        if ($failedCount === 0) {
            return HealthResult::ok("{$rendered} rendered, 0 failed (24h)");
        }

        /** @var VideoMetric $latest */
        $latest = $failed->latest('created_at')->first();
        $error = mb_substr((string) $latest->error, 0, 120);

        return HealthResult::error("{$rendered} rendered, {$failedCount} failed (24h). Last: Task #{$latest->yak_task_id}: {$error}");
    }
}
