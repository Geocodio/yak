<?php

namespace App\Services\Threads;

/**
 * Reads event names off broker and runner lines, accepting both wire
 * spellings: `{"type":"broker","event":"x"}` and `{"type":"broker.x"}`.
 */
final class BrokerLine
{
    private const BROKER_EVENTS = ['runner_started', 'runner_exited', 'restarted', 'start_refused', 'kill_done', 'no_runner', 'broker_started'];

    private const RUNNER_EVENTS = ['started', 'interrupted', 'error', 'exit_after_result', 'done'];

    /**
     * @param  array<string, mixed>  $line
     */
    public static function event(array $line): ?string
    {
        return self::named('broker', $line, self::BROKER_EVENTS);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function runnerEvent(array $line): ?string
    {
        return self::named('runner', $line, self::RUNNER_EVENTS);
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  list<string>  $allowed
     */
    private static function named(string $prefix, array $line, array $allowed): ?string
    {
        $type = $line['type'] ?? null;
        $name = match (true) {
            $type === $prefix => $line['event'] ?? null,
            is_string($type) && str_starts_with($type, $prefix . '.') => substr($type, strlen($prefix) + 1),
            default => null,
        };

        return is_string($name) && in_array($name, $allowed, true) ? $name : null;
    }
}
