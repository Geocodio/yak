<?php

use App\Models\TaskRun;
use App\Models\TelemetryEvent;

test('prune deletes events older than the retention window and keeps runs', function () {
    config(['yak.telemetry.retention_days' => 30]);

    TelemetryEvent::factory()->create(['occurred_at' => now()->subDays(31)]);
    TelemetryEvent::factory()->create(['occurred_at' => now()->subDays(29)]);
    TaskRun::factory()->create(['started_at' => now()->subDays(400)]);

    $this->artisan('yak:telemetry:prune')
        ->expectsOutputToContain('Pruned 1 telemetry event(s) older than 30 days.')
        ->assertSuccessful();

    expect(TelemetryEvent::count())->toBe(1)
        ->and(TaskRun::count())->toBe(1);
});

test('export writes runs as csv and events as json lines', function () {
    TaskRun::factory()->create(['started_at' => now()->subDay(), 'cost_usd' => 1.5]);
    TelemetryEvent::factory()->create(['occurred_at' => now()->subDay(), 'name' => 'feature.used']);

    $csv = tempnam(sys_get_temp_dir(), 'runs') . '.csv';
    $jsonl = tempnam(sys_get_temp_dir(), 'events') . '.jsonl';

    $this->artisan('yak:telemetry:export', ['table' => 'runs', '--output' => $csv])->assertSuccessful();
    $this->artisan('yak:telemetry:export', ['table' => 'events', '--format' => 'jsonl', '--output' => $jsonl])->assertSuccessful();

    $lines = file($csv, FILE_IGNORE_NEW_LINES);
    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('cost_usd')
        ->and($lines[1])->toContain('1.5');

    $decoded = json_decode((string) file_get_contents($jsonl), true);
    expect($decoded['name'])->toBe('feature.used')
        ->and($decoded['properties'])->toBe('{"feature":"follow_up"}');

    unlink($csv);
    unlink($jsonl);
});

test('export rejects an unknown table', function () {
    $this->artisan('yak:telemetry:export', ['table' => 'nope'])->assertFailed();
});
