<?php

namespace App\Services\Telemetry;

use App\Models\YakTask;
use App\Services\Telemetry\Contracts\TelemetrySink;
use App\Support\TaskContext;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one door into telemetry_events. Every emitter calls record() or
 * time(); nothing writes the table directly. Disabled telemetry is a
 * no-op, and a failing write is logged and swallowed so bookkeeping can
 * never break a job or a webhook -- the same contract RecordAiUsage has.
 */
class Telemetry
{
    public function __construct(
        private readonly TelemetrySink $sink,
        private readonly bool $enabled,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record one event. `repo` and `source` default from the task; the
     * task itself defaults from TaskContext so code deep inside a job can
     * emit without threading the model through.
     *
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $name,
        array $properties = [],
        ?YakTask $task = null,
        ?string $repo = null,
        ?string $source = null,
        ?int $durationMs = null,
        ?float $value = null,
        ?Model $subject = null,
        ?int $runId = null,
        ?DateTimeInterface $occurredAt = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $taskId = $task !== null ? $task->id : TaskContext::currentTaskId();

        try {
            $this->sink->write([
                'occurred_at' => $occurredAt ?? now(),
                'name' => $name,
                'repo' => $repo ?? $task?->repo,
                'source' => $source ?? $task?->source,
                'yak_task_id' => $taskId,
                'task_run_id' => $runId ?? RunContext::currentRunId(),
                'subject_type' => $subject !== null ? class_basename($subject) : null,
                'subject_id' => $subject !== null ? (int) $subject->getKey() : null,
                'duration_ms' => $durationMs,
                'value' => $value,
                'properties' => $properties === [] ? null : $properties,
            ]);
        } catch (Throwable $e) {
            Log::channel('yak')->warning('Telemetry: failed to record event', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run the callback and record how long it took. An exception is
     * recorded with `failed: true` and the exception class, then rethrown.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  array<string, mixed>  $properties
     * @return TReturn
     */
    public function time(
        string $name,
        Closure $callback,
        array $properties = [],
        ?YakTask $task = null,
        ?Model $subject = null,
    ): mixed {
        if (! $this->enabled) {
            return $callback();
        }

        $startedAt = hrtime(true);

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $this->record(
                $name,
                $properties + ['failed' => true, 'exception' => class_basename($e)],
                task: $task,
                durationMs: self::elapsedMs($startedAt),
                subject: $subject,
            );

            throw $e;
        }

        $this->record($name, $properties, task: $task, durationMs: self::elapsedMs($startedAt), subject: $subject);

        return $result;
    }

    /**
     * Shorthand for the `feature.used` event that the usage grid on the
     * Analytics page is built from.
     *
     * @param  array<string, mixed>  $properties
     */
    public function feature(string $feature, array $properties = [], ?YakTask $task = null, ?string $repo = null, ?string $source = null): void
    {
        $this->record('feature.used', ['feature' => $feature] + $properties, task: $task, repo: $repo, source: $source);
    }

    public static function elapsedMs(int $startedAtNs): int
    {
        return (int) round((hrtime(true) - $startedAtNs) / 1_000_000);
    }
}
