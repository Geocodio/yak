<?php

use App\Models\Observation;

test('deletes observations past the retention window and keeps the rest', function () {
    config()->set('yak.ci_scan.observation_retention_days', 30);

    Observation::factory()->create(['summary' => 'stale', 'created_at' => now()->subDays(31)]);
    Observation::factory()->create(['summary' => 'fresh', 'created_at' => now()->subDays(29)]);

    $this->artisan('yak:observations:prune')
        ->assertSuccessful()
        ->expectsOutputToContain('Pruned 1 observation(s)');

    expect(Observation::pluck('summary')->all())->toBe(['fresh']);
});
