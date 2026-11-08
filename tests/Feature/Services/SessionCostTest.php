<?php

use App\Models\TaskRun;
use App\Services\SessionCost;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the reported total for a session with no earlier run', function () {
    expect(SessionCost::incremental('s1', 1.25))->toBe(1.25);
});

it('subtracts the latest recorded total for the same session', function () {
    TaskRun::factory()->create(['session_id' => 's1', 'session_cost_total_usd' => 0.5]);
    TaskRun::factory()->create(['session_id' => 's1', 'session_cost_total_usd' => 1.25]);
    TaskRun::factory()->create(['session_id' => 's2', 'session_cost_total_usd' => 9.0]);

    expect(SessionCost::incremental('s1', 2.0))->toBe(0.75);
});

it('never returns a negative cost', function () {
    TaskRun::factory()->create(['session_id' => 's1', 'session_cost_total_usd' => 2.0]);

    expect(SessionCost::incremental('s1', 1.0))->toBe(1.0);
});

it('treats a null session as a fresh session', function () {
    TaskRun::factory()->create(['session_id' => 's1', 'session_cost_total_usd' => 2.0]);

    expect(SessionCost::incremental(null, 1.5))->toBe(1.5);
});
