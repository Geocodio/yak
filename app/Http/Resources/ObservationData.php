<?php

namespace App\Http\Resources;

use App\Models\Observation;

/**
 * Flattens an {@see Observation} into the shape the Observations page
 * renders. The array shape below is the source of truth for
 * `resources/js/types/observations.ts`.
 */
final class ObservationData
{
    /**
     * @return array{
     *     id: int,
     *     repo: ?string,
     *     source: string,
     *     kind: string,
     *     kindLabel: string,
     *     outcome: string,
     *     summary: string,
     *     subject: ?string,
     *     referenceUrl: ?string,
     *     taskId: ?int,
     *     taskUrl: ?string,
     *     createdAgo: string,
     *     createdAt: string,
     *     createdTooltip: string,
     * }
     */
    public static function from(Observation $observation): array
    {
        return [
            'id' => $observation->id,
            'repo' => $observation->repo,
            'source' => $observation->source,
            'kind' => $observation->kind,
            'kindLabel' => self::label($observation->kind),
            'outcome' => $observation->outcome,
            'summary' => $observation->summary,
            'subject' => $observation->subject,
            'referenceUrl' => $observation->reference_url,
            'taskId' => $observation->yak_task_id,
            'taskUrl' => $observation->yak_task_id !== null
                ? route('tasks.show', $observation->yak_task_id)
                : null,
            'createdAgo' => $observation->created_at->diffForHumans(short: true),
            'createdAt' => $observation->created_at->toIso8601String(),
            'createdTooltip' => $observation->created_at->toDayDateTimeString(),
        ];
    }

    /**
     * Human labels for the machine kinds. An unknown kind falls back to its
     * own key rather than disappearing, so a new kind is visible on the page
     * before anyone gets round to naming it.
     */
    private static function label(string $kind): string
    {
        return match ($kind) {
            'flaky_test.task_created' => 'Task opened',
            'flaky_test.below_threshold' => 'Below threshold',
            'flaky_test.already_claimed' => 'Already handled',
            'flaky_test.existing_pr' => 'PR already out',
            'flaky_test.no_commit' => 'No commit',
            default => $kind,
        };
    }
}
