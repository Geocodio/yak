<?php

namespace App\Services\Telemetry;

/**
 * Worker-scoped pointer to the task_runs row currently being recorded,
 * so events emitted from inside a run attach to it without every call
 * site threading the id through. Mirrors App\Support\TaskContext.
 */
final class RunContext
{
    private static ?int $runId = null;

    public static function set(?int $runId): void
    {
        self::$runId = $runId;
    }

    public static function clear(): void
    {
        self::$runId = null;
    }

    public static function currentRunId(): ?int
    {
        return self::$runId;
    }
}
