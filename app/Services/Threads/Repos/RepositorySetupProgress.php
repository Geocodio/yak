<?php

namespace App\Services\Threads\Repos;

use App\Models\Repository;
use App\Models\TaskLog;

/**
 * Where a repository's setup stands, in words the start screen can show: the
 * status, and the newest step the setup task has logged that has a friendly name.
 */
final class RepositorySetupProgress
{
    /** @var array<string, string> log message prefix => step label */
    private const array STEPS = [
        'Picked up by worker' => 'Starting a sandbox',
        'Sandbox created' => 'Sandbox started',
        'Cloning ' => 'Cloning the repository',
        'Starting Claude agent' => 'Reading the repository and preparing its setup',
    ];

    /**
     * @return array{status: 'pending'|'running'|'ready'|'failed', step: ?string}
     */
    public static function for(Repository $repository): array
    {
        $status = match ($repository->setup_status) {
            'running' => 'running',
            'ready' => 'ready',
            'failed' => 'failed',
            default => 'pending',
        };

        return [
            'status' => $status,
            'step' => $status === 'running' ? self::step($repository) : null,
        ];
    }

    private static function step(Repository $repository): ?string
    {
        if ($repository->setup_task_id === null) {
            return null;
        }

        $messages = TaskLog::query()->where('yak_task_id', $repository->setup_task_id)->orderByDesc('id')->limit(20)->pluck('message');

        foreach ($messages as $message) {
            foreach (self::STEPS as $prefix => $label) {
                if (str_starts_with((string) $message, $prefix)) {
                    return $label;
                }
            }
        }

        return null;
    }
}
