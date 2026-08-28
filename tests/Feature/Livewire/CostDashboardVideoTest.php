<?php

use App\Livewire\CostDashboard;
use App\Models\User;
use App\Models\VideoMetric;
use Livewire\Livewire;

test('cost dashboard shows video render counts, average render time and output size for the period', function () {
    $this->actingAs(User::factory()->create());

    VideoMetric::factory()->create(['render_ms' => 60_000, 'output_bytes' => 10 * 1024 * 1024]);
    VideoMetric::factory()->create(['render_ms' => 120_000, 'output_bytes' => 20 * 1024 * 1024]);
    VideoMetric::factory()->failed()->create();
    VideoMetric::factory()->create(['created_at' => now()->subDays(60)]); // outside default 30-day period

    $component = Livewire::test(CostDashboard::class);

    expect($component->instance()->videoSummary)->toBe([
        'rendered' => 2,
        'failed' => 1,
        'avg_render' => '1m 30s',
        'total_mb' => '30.0',
    ]);

    $component->assertSee('Videos rendered')->assertSee('1 failed')->assertSee('1m 30s');
});
