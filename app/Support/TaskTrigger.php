<?php

namespace App\Support;

use App\Models\YakTask;

/**
 * Names the specific thing that started an automated task -- the Sentry
 * issue or the flaky tests -- so the task page and the PR body can say
 * exactly what the work is about, not just which channel it came from.
 */
class TaskTrigger
{
    /**
     * @return array{label: string, url: ?string, lines: list<array{text: string, url: ?string}>}|null
     */
    public static function describe(YakTask $task): ?array
    {
        /** @var array<string, mixed> $context */
        $context = json_decode((string) $task->context, true) ?: [];

        return match ((string) $task->source) {
            'sentry' => [
                'label' => 'Sentry issue ' . ($context['sentry_issue_id'] ?? $task->external_id),
                'url' => TaskSourceUrl::resolve($task),
                'lines' => ($context['error'] ?? '') !== '' ? [['text' => (string) $context['error'], 'url' => null]] : [],
            ],
            'flaky-test' => [
                'label' => 'CI build',
                'url' => TaskSourceUrl::resolve($task),
                'lines' => array_map(
                    fn (array $test): array => [
                        'text' => (string) $test['test_name'],
                        'url' => $test['build_urls'][0] ?? null,
                    ],
                    array_values($context['tests'] ?? []),
                ),
            ],
            default => null,
        };
    }
}
