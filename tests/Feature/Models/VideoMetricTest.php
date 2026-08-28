<?php

use App\Models\VideoMetric;
use App\Models\YakTask;

test('records a rendered and a failed metric and filters by date range', function () {
    $task = YakTask::factory()->success()->create();

    VideoMetric::factory()->for($task, 'task')->create(['status' => 'rendered', 'render_ms' => 42000, 'output_bytes' => 1234, 'duration_seconds' => 33.2]);
    VideoMetric::factory()->for($task, 'task')->failed()->create(['created_at' => now()->subDays(40)]);

    expect(VideoMetric::count())->toBe(2)
        ->and(VideoMetric::between(now()->subDays(30), now())->count())->toBe(1)
        ->and(VideoMetric::between(now()->subDays(30), now())->first()->task->is($task))->toBeTrue()
        ->and(VideoMetric::where('status', 'failed')->first()->error)->not->toBeNull();
});
