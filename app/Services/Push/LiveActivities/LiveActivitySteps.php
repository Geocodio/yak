<?php

namespace App\Services\Push\LiveActivities;

use App\Enums\ThreadBlockReason;

/**
 * The fixed phrases a Live Activity shows. A tool's input never reaches a lock screen, only the label of its kind.
 */
final class LiveActivitySteps
{
    public const STARTING = 'Starting';

    /**
     * The name of the last tool a handled assistant line uses.
     *
     * @param  array<string, mixed>  $line
     */
    public static function toolOf(array $line): ?string
    {
        if (($line['type'] ?? null) !== 'assistant') {
            return null;
        }

        $tool = null;

        foreach ((array) ($line['message']['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && is_string($block['name'] ?? null)) {
                $tool = $block['name'];
            }
        }

        return $tool;
    }

    public static function forTool(string $tool): string
    {
        return match ($tool) {
            'Bash' => 'Running a command',
            'Read' => 'Reading files',
            'Grep', 'Glob' => 'Searching the code',
            'Edit', 'Write', 'MultiEdit', 'NotebookEdit' => 'Editing files',
            'WebFetch', 'WebSearch' => 'Looking something up',
            default => 'Working',
        };
    }

    public static function forWaiting(ThreadBlockReason $reason): ?string
    {
        return match ($reason) {
            ThreadBlockReason::WaitingForAccount => 'Waiting for an account',
            ThreadBlockReason::WaitingForSandbox => 'Waiting for a sandbox',
            ThreadBlockReason::WaitingForRepository => 'Waiting for the repository',
            default => null,
        };
    }

    public static function needsPerson(ThreadBlockReason $reason): bool
    {
        return in_array($reason, [
            ThreadBlockReason::UsageLimit,
            ThreadBlockReason::CredentialExposure,
            ThreadBlockReason::RestoreFailed,
            ThreadBlockReason::AccountInvalid,
            ThreadBlockReason::AccountExpired,
            ThreadBlockReason::RepositorySetupFailed,
        ], true);
    }
}
