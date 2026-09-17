<?php

use App\Enums\TaskStatus;
use App\Facades\Telemetry;
use App\Models\PrReview;
use App\Models\TelemetryEvent;
use App\Models\YakTask;
use App\Services\Telemetry\Contracts\TelemetrySink;
use App\Services\Telemetry\Sinks\DatabaseSink;
use App\Services\Telemetry\Telemetry as TelemetryService;
use App\Support\TaskContext;

afterEach(function () {
    TaskContext::clear();
});

test('record writes a row with task, repo and source filled from the task', function () {
    $task = YakTask::factory()->create(['repo' => 'acme/widgets', 'source' => 'linear']);

    Telemetry::record('feature.used', ['feature' => 'follow_up'], task: $task, durationMs: 42, value: 3.5);

    $event = TelemetryEvent::sole();
    expect($event->name)->toBe('feature.used')
        ->and($event->repo)->toBe('acme/widgets')
        ->and($event->source)->toBe('linear')
        ->and($event->yak_task_id)->toBe($task->id)
        ->and($event->duration_ms)->toBe(42)
        ->and($event->value)->toBe(3.5)
        ->and($event->properties)->toBe(['feature' => 'follow_up']);
});

test('record defaults the task from TaskContext and records a subject', function () {
    $task = YakTask::factory()->create();
    $review = PrReview::factory()->create(['yak_task_id' => $task->id]);
    TaskContext::set($task);

    Telemetry::record('review.submitted', subject: $review);

    $event = TelemetryEvent::sole();
    expect($event->yak_task_id)->toBe($task->id)
        ->and($event->subject_type)->toBe('PrReview')
        ->and($event->subject_id)->toBe($review->id)
        ->and($event->properties)->toBeNull();
});

test('time records the duration and rethrows a failure with the exception name', function () {
    $value = Telemetry::time('sandbox.create', fn () => 'ready', ['container' => 'task-1']);

    expect($value)->toBe('ready');
    expect(TelemetryEvent::sole()->properties)->toBe(['container' => 'task-1']);

    expect(fn () => Telemetry::time('git.push', fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    $failed = TelemetryEvent::where('name', 'git.push')->sole();
    expect($failed->properties)->toMatchArray(['failed' => true, 'exception' => 'RuntimeException'])
        ->and($failed->duration_ms)->toBeInt();
});

test('nothing is written when telemetry is disabled', function () {
    $telemetry = new TelemetryService(new DatabaseSink, enabled: false);

    $telemetry->record('feature.used', ['feature' => 'steering']);
    $ran = $telemetry->time('git.push', fn () => 'pushed');

    expect($ran)->toBe('pushed')
        ->and($telemetry->enabled())->toBeFalse()
        ->and(TelemetryEvent::count())->toBe(0);
});

test('a failing sink never breaks the caller', function () {
    $sink = new class implements TelemetrySink
    {
        public function write(array $row): void
        {
            throw new RuntimeException('disk full');
        }
    };

    $telemetry = new TelemetryService($sink, enabled: true);

    $telemetry->record('feature.used');

    expect(TelemetryEvent::count())->toBe(0);
});

test('a status transition records task.status_changed and a terminal one records task.finished', function () {
    $task = YakTask::factory()->create([
        'created_at' => now()->subMinutes(10),
        'cost_usd' => 1.25,
        'num_turns' => 12,
        'duration_ms' => 90_000,
    ]);

    $task->update(['status' => TaskStatus::Running, 'started_at' => now()->subMinutes(9)]);

    $changed = TelemetryEvent::where('name', 'task.status_changed')->sole();
    expect($changed->properties)->toMatchArray(['from' => 'pending', 'to' => 'running'])
        ->and(TelemetryEvent::where('name', 'task.finished')->count())->toBe(0);

    $task->update(['status' => TaskStatus::Success, 'completed_at' => now()]);

    $finished = TelemetryEvent::where('name', 'task.finished')->sole();
    expect($finished->properties)->toMatchArray(['status' => 'success', 'cost_usd' => 1.25, 'num_turns' => 12, 'agent_ms' => 90_000])
        ->and($finished->duration_ms)->toBeGreaterThanOrEqual(599_000)
        ->and($finished->properties['queue_wait_ms'])->toBeGreaterThanOrEqual(59_000);
});
