<?php

use App\Models\TaskRun;
use App\Services\Threads\RunawayGuard;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['yak.max_turns' => 10, 'yak.threads.turn_wall_clock_minutes' => 60]);
    $this->now = CarbonImmutable::parse('2026-11-11 12:00:00');
});

function runStartedAt(?CarbonImmutable $startedAt): TaskRun
{
    $run = new TaskRun;
    $run->agent_started_at = $startedAt;

    return $run;
}

it('trips the assistant turn limit at equality, not below', function () {
    $run = runStartedAt($this->now);

    expect((new RunawayGuard)->check($run, $this->now, 9))->toBeNull();
    expect((new RunawayGuard)->check($run, $this->now, 10))->toBe(RunawayGuard::ASSISTANT_TURNS);
    expect((new RunawayGuard)->check($run, $this->now, 11))->toBe(RunawayGuard::ASSISTANT_TURNS);
});

it('trips the wall clock limit at equality, not below', function () {
    expect((new RunawayGuard)->check(runStartedAt($this->now->subMinutes(59)->subSeconds(59)), $this->now, 0))->toBeNull();
    expect((new RunawayGuard)->check(runStartedAt($this->now->subMinutes(60)), $this->now, 0))->toBe(RunawayGuard::WALL_CLOCK);
});

it('falls back to started_at when agent_started_at is null', function () {
    $run = runStartedAt(null);
    $run->started_at = $this->now->subMinutes(90);

    expect((new RunawayGuard)->check($run, $this->now, 0))->toBe(RunawayGuard::WALL_CLOCK);
});

it('never trips wall clock when no start time exists', function () {
    expect((new RunawayGuard)->check(runStartedAt(null), $this->now, 0))->toBeNull();
});

it('reports the assistant reason when both limits trip', function () {
    $run = runStartedAt($this->now->subHours(5));

    expect((new RunawayGuard)->check($run, $this->now, 50))->toBe(RunawayGuard::ASSISTANT_TURNS);
});

it('words the stop message per reason', function () {
    $guard = new RunawayGuard;

    expect($guard->message(RunawayGuard::ASSISTANT_TURNS, 10))
        ->toBe('Yak stopped this turn after 10 assistant turns. Reply or press Continue to keep going.');
    expect($guard->message(RunawayGuard::WALL_CLOCK, 10))
        ->toBe('Yak stopped this turn after 60 minutes. Reply or press Continue to keep going.');
});

it('trips wall clock for a run whose agent_started_at is a mutable Carbon', function () {
    config(['yak.threads.turn_wall_clock_minutes' => 240]);
    $now = CarbonImmutable::now();

    $run = new TaskRun;
    $run->agent_started_at = now()->subMinutes(241);
    expect((new RunawayGuard)->check($run, $now, 0))->toBe(RunawayGuard::WALL_CLOCK);

    $run = new TaskRun;
    $run->agent_started_at = now()->subMinutes(239);
    expect((new RunawayGuard)->check($run, $now, 0))->toBeNull();
    expect((new RunawayGuard)->check($run, $now, (int) config('yak.max_turns')))->toBe(RunawayGuard::ASSISTANT_TURNS);
});
